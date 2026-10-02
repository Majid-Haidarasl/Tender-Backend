<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Validate required environment variables
        $this->validateEnvironmentVariables();
    }

    /**
     * Validate required environment variables
     */
    protected function validateEnvironmentVariables(): void
    {
        $required = [
            'app.key' => 'APP_KEY',
            'database.default' => 'DB_CONNECTION',
            'database.connections.mysql.host' => 'DB_HOST',
            'database.connections.mysql.database' => 'DB_DATABASE',
            'database.connections.mysql.username' => 'DB_USERNAME',
            'database.connections.mysql.password' => 'DB_PASSWORD',
            'tender.jwt_secret' => 'JWT_SECRET',
        ];

        $missing = [];
        foreach ($required as $configKey => $envName) {
            if (empty(config($configKey))) {
                $missing[] = $envName;
            }
        }

        if (!empty($missing) && !app()->runningInConsole()) {
            \Log::warning('Missing required environment variables', [
                'missing' => $missing,
                'message' => 'Application may not function correctly without these variables'
            ]);
        }
    }
}

