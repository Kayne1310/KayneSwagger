<?php

namespace Kayne\Swagger;

use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Kayne\Swagger\Attributes\Api;

class SwaggerGenerator
{
    private array $spec = [];

    public function __construct()
    {
        $this->spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => config('swagger.title', 'API Documentation'),
                'version' => config('swagger.version', '2.0.0'),
                'description' => config('swagger.description', ''),
            ],
            'servers' => [
                ['url' => config('app.url')],
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
                'securitySchemes' => config('swagger.security_schemes', []),
            ],
        ];
    }

    /**
     * Scan và generate spec từ routes
     */
    public function generate(): array
    {
        $routes = Route::getRoutes();

        foreach ($routes as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || strpos($action, '@') === false) {
                continue;
            }

            [$controller, $method] = explode('@', $action);

            if (!class_exists($controller)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($controller);
                if (!$reflection->hasMethod($method)) {
                    continue;
                }

                $methodReflection = $reflection->getMethod($method);
                $this->processMethod($route, $methodReflection);
            } catch (\Exception $e) {
                continue;
            }
        }

        return $this->spec;
    }

    /**
     * Xử lý method và tạo path spec
     */
    private function processMethod($route, ReflectionMethod $method): void
    {
        $attributes = $method->getAttributes(Api::class);

        if (empty($attributes)) {
            return;
        }

        $apiAttr = $attributes[0]->newInstance();

        $path = $apiAttr->path;
        $httpMethod = strtolower($apiAttr->method);

        if (!isset($this->spec['paths'][$path])) {
            $this->spec['paths'][$path] = [];
        }

        // Auto-generate summary nếu không có
        $summary = $apiAttr->summary ?? $this->generateSummary($method->getName(), $httpMethod);

        $operation = [
            'summary' => $summary,
            'tags' => $apiAttr->tags,
        ];

        if ($apiAttr->description) {
            $operation['description'] = $apiAttr->description;
        }

        // Tự động detect parameters (path, query, body)
        $this->processParameters($operation, $method, $route, $httpMethod);

        // Response - mặc định 200 nếu không set
        $responseCode = $apiAttr->responseCode ?? 200;
        $operation['responses'] = [
            (string)$responseCode => [
                'description' => 'Success',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object'
                        ]
                    ]
                ]
            ]
        ];

        // Thêm response type nếu có
        if ($apiAttr->responseType && class_exists($apiAttr->responseType)) {
            if (is_subclass_of($apiAttr->responseType, BaseDto::class)) {
                $schemaName = $this->getSchemaName($apiAttr->responseType);

                if (!isset($this->spec['components']['schemas'][$schemaName])) {
                    $this->spec['components']['schemas'][$schemaName] = SchemaGenerator::generate($apiAttr->responseType);
                }

                $operation['responses'][(string)$responseCode]['content']['application/json']['schema'] = [
                    '$ref' => "#/components/schemas/{$schemaName}"
                ];
            }
        }

        // Xử lý Security
        $this->processSecurity($operation, $apiAttr, $route);

        $this->spec['paths'][$path][$httpMethod] = $operation;
    }

    /**
     * Tự động detect và xử lý parameters (thông minh như .NET)
     */
    private function processParameters(array &$operation, ReflectionMethod $method, $route, string $httpMethod): void
    {
        $parameters = $method->getParameters();
        $pathParams = $this->extractPathParameters($route->uri());
        $operation['parameters'] = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $type = $param->getType();

            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            // Nếu là DTO hoặc FormRequestDto → Request Body (cho POST, PUT, PATCH)
            if (class_exists($typeName) && (is_subclass_of($typeName, BaseDto::class) || is_subclass_of($typeName, FormRequestDto::class))) {
                if (in_array($httpMethod, ['post', 'put', 'patch'])) {
                    $schemaName = $this->getSchemaName($typeName);

                    // Thêm schema vào components
                    if (!isset($this->spec['components']['schemas'][$schemaName])) {
                        $this->spec['components']['schemas'][$schemaName] = SchemaGenerator::generate($typeName);
                    }

                    $operation['requestBody'] = [
                        'required' => !$type->allowsNull(),
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}"
                                ]
                            ]
                        ]
                    ];
                }
                continue;
            }

            // Nếu param name match với path parameter → Path Parameter
            if (in_array($paramName, $pathParams)) {
                $operation['parameters'][] = [
                    'name' => $paramName,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $this->mapPhpTypeToSchema($typeName),
                ];
                continue;
            }

            // Còn lại → Query Parameter
            if ($typeName !== 'Illuminate\Http\Request') {
                $operation['parameters'][] = [
                    'name' => $paramName,
                    'in' => 'query',
                    'required' => !$type->allowsNull(),
                    'schema' => $this->mapPhpTypeToSchema($typeName),
                ];
            }
        }

        // Xóa key parameters nếu rỗng
        if (empty($operation['parameters'])) {
            unset($operation['parameters']);
        }
    }

    /**
     * Extract path parameters từ route URI
     */
    private function extractPathParameters(string $uri): array
    {
        preg_match_all('/{([^}]+)}/', $uri, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Map PHP type sang OpenAPI schema
     */
    private function mapPhpTypeToSchema(string $phpType): array
    {
        return match($phpType) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number', 'format' => 'float'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'string' => ['type' => 'string'],
            default => ['type' => 'string'],
        };
    }

    /**
     * Tự động generate summary từ method name
     */
    private function generateSummary(string $methodName, string $httpMethod): string
    {
        // Convert camelCase to words
        $words = preg_replace('/([a-z])([A-Z])/', '$1 $2', $methodName);
        $words = ucwords($words);

        // Map common method names
        $map = [
            'index' => 'List all',
            'store' => 'Create new',
            'show' => 'Get',
            'update' => 'Update',
            'destroy' => 'Delete',
        ];

        if (isset($map[strtolower($methodName)])) {
            return $map[strtolower($methodName)];
        }

        return $words;
    }

    /**
     * Lấy tên schema từ class name
     */
    private function getSchemaName(string $className): string
    {
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * Xử lý Security cho operation
     */
    private function processSecurity(array &$operation, Api $apiAttr, $route): void
    {
        $security = null;

        // 1. Ưu tiên: Security từ Api attribute
        if ($apiAttr->security !== null && !empty($apiAttr->security)) {
            $security = $apiAttr->security;
        }
        // 2. Tự động detect từ middleware (nếu enabled)
        elseif (config('swagger.auto_detect_security', true)) {
            $security = $this->detectSecurityFromMiddleware($route);
        }
        // 3. Global security (nếu có)
        if ($security === null) {
            $globalSecurity = config('swagger.global_security', []);
            if (!empty($globalSecurity)) {
                $security = $globalSecurity;
            }
        }

        // Apply security nếu có
        if ($security !== null && !empty($security)) {
            $operation['security'] = array_map(function($scheme) {
                return [$scheme => []];
            }, $security);
        }
    }

    /**
     * Tự động detect security từ route middleware
     */
    private function detectSecurityFromMiddleware($route): ?array
    {
        $middlewareMap = config('swagger.middleware_security_map', []);
        $middlewares = $route->middleware();

        if (empty($middlewares)) {
            return null;
        }

        foreach ($middlewares as $middleware) {
            // Handle middleware với alias (auth:sanctum) hoặc object
            $middlewareName = is_string($middleware) ? $middleware : null;
            
            if (!$middlewareName) {
                continue;
            }

            // Exact match
            if (isset($middlewareMap[$middlewareName])) {
                return [$middlewareMap[$middlewareName]];
            }

            // Partial match (auth, sanctum, jwt, etc.)
            foreach ($middlewareMap as $key => $scheme) {
                if (strpos($middlewareName, $key) !== false) {
                    return [$scheme];
                }
            }
        }

        return null;
    }

    /**
     * Export spec ra JSON
     */
    public function toJson(): string
    {
        return json_encode($this->spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
