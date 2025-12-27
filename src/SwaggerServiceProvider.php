<?php

namespace Kayne\Swagger;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Kayne\Swagger\Middleware\ValidateDto;

class SwaggerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/swagger.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'swagger');

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/swagger.php' => config_path('swagger.php'),
        ], 'swagger-config');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/swagger'),
        ], 'swagger-views');

        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('dto', ValidateDto::class);
    }

    /**
     * Register services
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/swagger.php',
            'swagger'
        );
    }
}
