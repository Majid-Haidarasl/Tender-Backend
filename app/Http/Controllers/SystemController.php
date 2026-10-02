<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Tender;
use App\Models\Estimate;
use App\Models\Index;
use App\Models\User;
use App\Services\PoCalculationService;

class SystemController extends Controller
{
    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        return $this->successResponse([
            'status' => 'OK',
            'message' => 'Tender Evaluation API is running',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Database connection and user check endpoint
     */
    public function checkDatabase(): JsonResponse
    {
        try {
            $checks = [
                'database_connected' => false,
                'users_table_exists' => false,
                'admin_user_exists' => false,
                'admin_user_active' => false,
                'total_users' => 0,
            ];

            // Check database connection
            try {
                \DB::connection()->getPdo();
                $checks['database_connected'] = true;
            } catch (\Exception $e) {
                return $this->errorResponse('Database connection failed: ' . $e->getMessage(), 500);
            }

            // Check if users table exists
            try {
                $tableExists = Schema::hasTable('users');
                $checks['users_table_exists'] = $tableExists;
            } catch (\Exception $e) {
                return $this->errorResponse('Error checking users table: ' . $e->getMessage(), 500);
            }

            if ($checks['users_table_exists']) {
                // Check admin user
                $adminUser = User::where('username', 'admin')->first();
                $checks['admin_user_exists'] = $adminUser !== null;
                $checks['admin_user_active'] = $adminUser && $adminUser->is_active;
                $checks['total_users'] = User::count();
            }

            return $this->successResponse($checks, 'Database check completed');
        } catch (\Exception $e) {
            return $this->errorResponse('Check failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Route status check endpoint (for debugging)
     */
    public function routeStatus(): JsonResponse
    {
        try {
            $status = [
                'notifications_routes' => [],
                'formulas_routes' => [],
                'messages_routes' => [],
                'controllers_exist' => [],
                'files_exist' => [],
                'route_cache' => [],
                'config_cache' => []
            ];
            
            // Check if routes are registered
            $routes = \Illuminate\Support\Facades\Route::getRoutes();
            foreach ($routes as $route) {
                $uri = $route->uri();
                if (strpos($uri, 'notifications') !== false) {
                    $status['notifications_routes'][] = [
                        'method' => implode('|', $route->methods()),
                        'uri' => $uri,
                        'action' => $route->getActionName()
                    ];
                }
                if (strpos($uri, 'formulas') !== false) {
                    $status['formulas_routes'][] = [
                        'method' => implode('|', $route->methods()),
                        'uri' => $uri,
                        'action' => $route->getActionName()
                    ];
                }
                if (strpos($uri, 'messages') !== false) {
                    $status['messages_routes'][] = [
                        'method' => implode('|', $route->methods()),
                        'uri' => $uri,
                        'action' => $route->getActionName()
                    ];
                }
            }
            
            // Check if controller files exist
            $controllerFiles = [
                'NotificationController' => base_path('app/Http/Controllers/NotificationController.php'),
                'FormulaController' => base_path('app/Http/Controllers/FormulaController.php'),
                'MessageController' => base_path('app/Http/Controllers/MessageController.php'),
            ];
            
            foreach ($controllerFiles as $name => $path) {
                $status['files_exist'][$name] = file_exists($path);
                if (file_exists($path)) {
                    $status['controllers_exist'][$name] = class_exists("App\\Http\\Controllers\\{$name}");
                } else {
                    $status['controllers_exist'][$name] = false;
                }
            }
            
            // Check routes/api.php
            $status['files_exist']['routes_api'] = file_exists(base_path('routes/api.php'));
            
            // Check cache files
            $routeCacheFile = base_path('bootstrap/cache/routes-v7.php');
            $configCacheFile = base_path('bootstrap/cache/config.php');
            $status['route_cache']['exists'] = file_exists($routeCacheFile);
            $status['route_cache']['path'] = $routeCacheFile;
            $status['config_cache']['exists'] = file_exists($configCacheFile);
            $status['config_cache']['path'] = $configCacheFile;
            
            // Check RouteServiceProvider
            $routeProvider = base_path('app/Providers/RouteServiceProvider.php');
            $status['files_exist']['RouteServiceProvider'] = file_exists($routeProvider);
            
            if (file_exists($routeProvider)) {
                $providerContent = file_get_contents($routeProvider);
                $status['route_provider']['has_api_routes'] = strpos($providerContent, 'routes/api.php') !== false;
            }
            
            return $this->successResponse($status, 'Route status retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Clear cache endpoint
     * SECURITY: Uses JWT + Admin middleware (configured in routes)
     * Token-based auth is deprecated but kept for backward compatibility with CI/CD
     */
    public function clearCache(Request $request): JsonResponse
    {
        // SECURITY: Prefer JWT authentication (via middleware)
        // Token-based auth is only for CI/CD automation
        $user = $request->user();
        
        // If user is authenticated via JWT, use that
        if ($user && $user->role === 'admin') {
            // User is authenticated via JWT middleware - proceed
        } elseif ($user && $user->role !== 'admin') {
            // User is authenticated but not admin
            return $this->unauthorizedResponse('Unauthorized - Admin access required');
        } else {
            // Fallback to token-based auth for CI/CD (POST body only, not query string)
            $token = $request->input('token');
            if (empty($token)) {
                return $this->unauthorizedResponse('Unauthorized - JWT token or admin token required');
            }
            
            // SECURITY: No default value - token must be set in environment
            $expectedToken = config('tender.cache_clear_token');
            if (empty($expectedToken)) {
                Log::error('CACHE_CLEAR_TOKEN is not set in environment');
                return $this->errorResponse('Server configuration error', 500);
            }
            
            if ($token !== $expectedToken) {
                Log::warning('Invalid cache clear token attempt', ['ip' => $request->ip()]);
                return $this->unauthorizedResponse('Unauthorized');
            }
        }
        
        try {
            $results = [];
            $output = [];
            $laravelRoot = base_path();
            
            // 1. Clear route cache using Artisan
            try {
                Artisan::call('route:clear');
                $results['route_artisan'] = 'cleared';
                $output[] = '✓ Route cache cleared (Artisan)';
            } catch (\Exception $e) {
                $output[] = '⚠ Route cache clear (Artisan) failed: ' . $e->getMessage();
            }
            
            // 2. Manually delete route cache files
            $routeCacheDir = $laravelRoot . '/bootstrap/cache';
            $routeFiles = glob($routeCacheDir . '/routes*.php');
            $routeFilesDeleted = 0;
            foreach ($routeFiles as $file) {
                if (is_file($file) && unlink($file)) {
                    $routeFilesDeleted++;
                    $output[] = '✓ Deleted: ' . basename($file);
                }
            }
            $results['route_files_deleted'] = $routeFilesDeleted;
            
            // 3. Clear config cache using Artisan
            try {
                Artisan::call('config:clear');
                $results['config_artisan'] = 'cleared';
                $output[] = '✓ Config cache cleared (Artisan)';
            } catch (\Exception $e) {
                $output[] = '⚠ Config cache clear (Artisan) failed: ' . $e->getMessage();
            }
            
            // 4. Manually delete config cache files
            $configFiles = glob($routeCacheDir . '/config*.php');
            $configFilesDeleted = 0;
            foreach ($configFiles as $file) {
                if (is_file($file) && unlink($file)) {
                    $configFilesDeleted++;
                    $output[] = '✓ Deleted: ' . basename($file);
                }
            }
            $results['config_files_deleted'] = $configFilesDeleted;
            
            // 5. Clear application cache
            try {
                Artisan::call('cache:clear');
                $results['cache'] = 'cleared';
                $output[] = '✓ Application cache cleared';
            } catch (\Exception $e) {
                $output[] = '⚠ Application cache clear failed: ' . $e->getMessage();
            }
            
            // 6. Clear view cache
            try {
                Artisan::call('view:clear');
                $results['view'] = 'cleared';
                $output[] = '✓ View cache cleared';
            } catch (\Exception $e) {
                $output[] = '⚠ View cache clear failed: ' . $e->getMessage();
            }
            
            // 7. Optimize clear
            try {
                Artisan::call('optimize:clear');
                $results['optimize'] = 'cleared';
                $output[] = '✓ Optimize cache cleared';
            } catch (\Exception $e) {
                $output[] = '⚠ Optimize cache clear failed: ' . $e->getMessage();
            }
            
            Log::info('Cache cleared via HTTP', ['results' => $results]);
            
            return $this->successResponse([
                'results' => $results,
                'output' => $output,
            ], 'All caches cleared successfully');
        } catch (\Exception $e) {
            Log::error('Cache clear failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse('Failed to clear cache: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Run database migrations
     * SECURITY: Uses JWT + Admin middleware (configured in routes)
     * Token-based auth is deprecated but kept for backward compatibility with CI/CD
     */
    public function migrate(Request $request): JsonResponse
    {
        // SECURITY: Prefer JWT authentication (via middleware)
        // Token-based auth is only for CI/CD automation
        $user = $request->user();
        
        // If user is authenticated via JWT, use that
        if ($user && $user->role === 'admin') {
            // User is authenticated via JWT middleware - proceed
        } elseif ($user && $user->role !== 'admin') {
            // User is authenticated but not admin
            return $this->unauthorizedResponse('Unauthorized - Admin access required');
        } else {
            // Fallback to token-based auth for CI/CD (POST body only, not query string)
            $token = $request->input('token');
            if (empty($token)) {
                return $this->unauthorizedResponse('Unauthorized - JWT token or admin token required');
            }
            
            // SECURITY: No default value - token must be set in environment
            $expectedToken = config('tender.migration_token');
            if (empty($expectedToken)) {
                Log::error('MIGRATION_TOKEN is not set in environment');
                return $this->errorResponse('Server configuration error', 500);
            }
            
            if ($token !== $expectedToken) {
                Log::warning('Invalid migration token attempt', ['ip' => $request->ip()]);
                return $this->unauthorizedResponse('Unauthorized');
            }
        }
        
        try {
            $output = [];
            $results = [];
            
            // Run migrations
            $output[] = 'Running database migrations...';
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
            $migrationOutput = Artisan::output();
            $results['migrations'] = 'completed';
            $output[] = '✓ Migrations completed successfully';
            if (!empty($migrationOutput)) {
                $output[] = 'Migration output: ' . trim($migrationOutput);
            }
            
            // Optional: Run seeders
            if (config('tender.run_seeders_during_migration')) {
                $output[] = '';
                $output[] = 'Running database seeders...';
                Artisan::call('db:seed', ['--force' => true, '--no-interaction' => true]);
                $seederOutput = Artisan::output();
                $results['seeders'] = 'completed';
                $output[] = '✓ Seeders completed successfully';
            } else {
                $results['seeders'] = 'skipped';
            }
            
            return $this->successResponse([
                'results' => $results,
                'output' => $output
            ], 'Database migrations completed successfully');
        } catch (\Exception $e) {
            Log::error('Migration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Migration failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Run database seeders
     * SECURITY: Uses JWT + Admin middleware (configured in routes)
     * Token-based auth is deprecated but kept for backward compatibility with CI/CD
     */
    public function seed(Request $request): JsonResponse
    {
        // SECURITY: Prefer JWT authentication (via middleware)
        // Token-based auth is only for CI/CD automation
        $user = $request->user();
        
        // If user is authenticated via JWT, use that
        if ($user && $user->role === 'admin') {
            // User is authenticated via JWT middleware - proceed
        } elseif ($user && $user->role !== 'admin') {
            // User is authenticated but not admin
            return $this->unauthorizedResponse('Unauthorized - Admin access required');
        } else {
            // Fallback to token-based auth for CI/CD (POST body only, not query string)
            $token = $request->input('token');
            if (empty($token)) {
                return $this->unauthorizedResponse('Unauthorized - JWT token or admin token required');
            }
            
            // SECURITY: No default value - token must be set in environment
            $expectedToken = config('tender.migration_token');
            if (empty($expectedToken)) {
                Log::error('MIGRATION_TOKEN is not set in environment');
                return $this->errorResponse('Server configuration error', 500);
            }
            
            if ($token !== $expectedToken) {
                Log::warning('Invalid seeder token attempt', ['ip' => $request->ip()]);
                return $this->unauthorizedResponse('Unauthorized');
            }
        }
        
        $seederClass = $request->input('seeder') ?? $request->query('seeder');
        
        if (empty($seederClass)) {
            return $this->errorResponse('Please provide seeder parameter', 400);
        }
        
        try {
            $seederFullClass = "Database\\Seeders\\{$seederClass}";
            if (!class_exists($seederFullClass)) {
                return $this->errorResponse("Seeder class '{$seederClass}' not found", 404);
            }
            
            if ($seederClass === 'DatabaseSeeder') {
                Artisan::call('db:seed', [
                    '--force' => true,
                    '--no-interaction' => true
                ]);
            } else {
                Artisan::call('db:seed', [
                    '--class' => $seederFullClass,
                    '--force' => true,
                    '--no-interaction' => true
                ]);
            }
            
            $results = ['seeder' => 'completed', 'seeder_class' => $seederClass];
            
            Log::info('Seeder executed via HTTP', ['seeder' => $seederClass]);
            
            return $this->successResponse($results, "Seeder '{$seederClass}' executed successfully");
        } catch (\Exception $e) {
            Log::error('Seeder execution failed', [
                'seeder' => $seederClass ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            
            return $this->errorResponse('Failed to run seeder: ' . $e->getMessage(), 500);
        }
    }
}
