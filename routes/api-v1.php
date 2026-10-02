<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Version 1 Routes
|--------------------------------------------------------------------------
|
| This file contains versioned API routes. This allows for API versioning
| and backward compatibility when making breaking changes.
|
*/

// All v1 routes are prefixed with /api/v1
Route::prefix('v1')->group(function () {
    // Health check endpoint
    Route::get('/health', function () {
        return response()->json([
            'status' => 'OK',
            'version' => '1.0',
            'message' => 'Tender Evaluation API v1 is running',
            'timestamp' => now()->toIso8601String(),
        ]);
    });
    
    // Add versioned routes here in the future
    // Example: Route::prefix('tenders')->group(base_path('routes/api-v1/tenders.php'));
});

