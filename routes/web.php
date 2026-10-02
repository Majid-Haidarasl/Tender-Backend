<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to Tender Evaluation System API',
        'version' => config('release.version', '3.0.0'),
        'api_base' => '/api'
    ]);
});

