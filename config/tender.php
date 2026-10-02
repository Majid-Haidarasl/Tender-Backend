<?php

return [
    'jwt_secret' => env('JWT_SECRET'),
    'frontend_urls' => array_filter(array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:5173,http://localhost:3000,http://127.0.0.1:5173')))),
    'cache_clear_token' => env('CACHE_CLEAR_TOKEN'),
    'migration_token' => env('MIGRATION_TOKEN'),
    'run_seeders_during_migration' => filter_var(env('MIGRATION_RUN_SEEDERS', false), FILTER_VALIDATE_BOOLEAN),
    'enable_system_routes' => filter_var(env('ENABLE_SYSTEM_ROUTES', false), FILTER_VALIDATE_BOOLEAN),
];
