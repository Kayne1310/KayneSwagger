<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Documentation Title
    |--------------------------------------------------------------------------
    */
    'title' => env('SWAGGER_TITLE', 'API Documentation'),

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    */
    'version' => env('SWAGGER_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | API Description
    |--------------------------------------------------------------------------
    */
    'description' => env('SWAGGER_DESCRIPTION', ''),

    /*
    |--------------------------------------------------------------------------
    | Swagger UI Route
    |--------------------------------------------------------------------------
    */
    'route' => env('SWAGGER_ROUTE', 'api/documentation'),

    /*
    |--------------------------------------------------------------------------
    | Enable Swagger UI
    |--------------------------------------------------------------------------
    */
    'enabled' => env('SWAGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    | Định nghĩa các authentication schemes cho Swagger
    | Có thể sử dụng: bearerAuth, apiKey, oauth2, etc.
    */
    'security_schemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'Enter JWT token (Bearer token)',
        ],
        // Có thể thêm các schemes khác:
        // 'apiKey' => [
        //     'type' => 'apiKey',
        //     'in' => 'header',
        //     'name' => 'X-API-Key',
        //     'description' => 'API Key authentication',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Security
    |--------------------------------------------------------------------------
    | Áp dụng security cho tất cả endpoints (nếu không set security trong Api attribute)
    | Để trống [] nếu muốn từng endpoint tự định nghĩa
    */
    'global_security' => env('SWAGGER_GLOBAL_SECURITY') 
        ? explode(',', env('SWAGGER_GLOBAL_SECURITY')) 
        : [],

    /*
    |--------------------------------------------------------------------------
    | Auto-detect Security from Middleware
    |--------------------------------------------------------------------------
    | Tự động detect security từ route middleware
    | Middleware names: auth, sanctum, jwt, etc.
    */
    'auto_detect_security' => env('SWAGGER_AUTO_DETECT_SECURITY', true),

    /*
    |--------------------------------------------------------------------------
    | Middleware to Security Mapping
    |--------------------------------------------------------------------------
    | Map middleware names sang security scheme names
    */
    'middleware_security_map' => [
        'auth' => 'bearerAuth',
        'auth:sanctum' => 'bearerAuth',
        'auth:api' => 'bearerAuth',
        'jwt' => 'bearerAuth',
        'jwt.auth' => 'bearerAuth',
        'sanctum' => 'bearerAuth',
    ],
];
