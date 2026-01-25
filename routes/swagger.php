<?php

use Illuminate\Support\Facades\Route;
use Kayne\Swagger\Http\Controllers\SwaggerController;

if (config('swagger.enabled', true)) {
    Route::get(config('swagger.route', 'api/documentation'), [SwaggerController::class, 'ui'])
        ->name('swagger.ui');

    Route::get(config('swagger.route', 'api/documentation') . '/spec', [SwaggerController::class, 'spec'])
        ->name('swagger.spec');

    Route::get(config('swagger.route', 'api/documentation') . '/postman', [SwaggerController::class, 'exportPostman'])
        ->name('swagger.postman');

    Route::get(config('swagger.route', 'api/documentation') . '/postman/environment', [SwaggerController::class, 'exportPostmanEnvironment'])
        ->name('swagger.postman.environment');
}
