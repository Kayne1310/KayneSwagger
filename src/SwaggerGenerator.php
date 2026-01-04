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
        $this->processParameters($operation, $method, $route, $httpMethod, $apiAttr);

        // Response - mặc định 200 nếu không set
        $responseCode = $apiAttr->responseCode ?? 200;
        $operation['responses'] = [
            (string)$responseCode => [
                'description' => $this->getResponseDescription($responseCode),
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

        // Tự động thêm error responses cho POST, PUT, PATCH, DELETE
        if (in_array($httpMethod, ['post', 'put', 'patch', 'delete'])) {
            $this->addErrorResponses($operation);
        }

        // Xử lý Security
        $this->processSecurity($operation, $apiAttr, $route);

        $this->spec['paths'][$path][$httpMethod] = $operation;
    }

    /**
     * Tự động detect và xử lý parameters (thông minh như .NET)
     */
    private function processParameters(array &$operation, ReflectionMethod $method, $route, string $httpMethod, Api $apiAttr): void
    {
        $parameters = $method->getParameters();
        // Extract path params từ Api path (không phải route URI) để match chính xác
        $pathParams = $this->extractPathParameters($apiAttr->path);
        $operation['parameters'] = [];

        // Xử lý path parameters trước (không phải DTO/FormRequestDto)
        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $type = $param->getType();

            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            // Skip DTO/FormRequestDto - sẽ xử lý sau
            if (class_exists($typeName) && (is_subclass_of($typeName, BaseDto::class) || is_subclass_of($typeName, FormRequestDto::class))) {
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

            // Còn lại → Query Parameter (chỉ cho primitive types)
            if ($typeName !== 'Illuminate\Http\Request') {
                $operation['parameters'][] = [
                    'name' => $paramName,
                    'in' => 'query',
                    'required' => !$type->allowsNull(),
                    'schema' => $this->mapPhpTypeToSchema($typeName),
                ];
            }
        }

        // Xử lý DTO/FormRequestDto sau
        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $type = $param->getType();

            if (!$type instanceof \ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            // Nếu là DTO hoặc FormRequestDto
            if (class_exists($typeName) && (is_subclass_of($typeName, BaseDto::class) || is_subclass_of($typeName, FormRequestDto::class))) {
                // GET request → Query Parameters
                if (in_array($httpMethod, ['get', 'head', 'options']) && is_subclass_of($typeName, FormRequestDto::class)) {
                    $this->processFormRequestAsQueryParams($operation, $typeName, $apiAttr);
                }
                // POST, PUT, PATCH → Request Body
                elseif (in_array($httpMethod, ['post', 'put', 'patch'])) {
                    $schemaName = $this->getSchemaName($typeName);

                    // Thêm schema vào components
                    if (!isset($this->spec['components']['schemas'][$schemaName])) {
                        $this->spec['components']['schemas'][$schemaName] = SchemaGenerator::generate($typeName);
                    }

                    // Xác định content type
                    $contentType = $this->getContentType($apiAttr);

                    $operation['requestBody'] = [
                        'required' => !$type->allowsNull(),
                        'content' => [
                            $contentType => [
                                'schema' => [
                                    '$ref' => "#/components/schemas/{$schemaName}"
                                ]
                            ]
                        ]
                    ];
                }
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
     * Tự động detect security từ route middleware (bao gồm route group middleware)
     */
    private function detectSecurityFromMiddleware($route): ?array
    {
        $middlewareMap = config('swagger.middleware_security_map', []);
        
        // Lấy tất cả middleware từ route (bao gồm cả route group middleware)
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

            // Exact match (ưu tiên cao nhất)
            if (isset($middlewareMap[$middlewareName])) {
                return [$middlewareMap[$middlewareName]];
            }

            // Partial match (auth, sanctum, jwt, token, etc.)
            // Kiểm tra xem middleware name có chứa key nào trong map không
            foreach ($middlewareMap as $key => $scheme) {
                // Exact match hoặc partial match (token trong token.verify)
                if ($middlewareName === $key || strpos($middlewareName, $key) !== false) {
                    return [$scheme];
                }
            }
        }

        return null;
    }

    /**
     * Xử lý FormRequestDto như Query Parameters (cho GET request)
     */
    private function processFormRequestAsQueryParams(array &$operation, string $formRequestClass, Api $apiAttr): void
    {
        $schema = SchemaGenerator::generate($formRequestClass);
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $field => $fieldSchema) {
            $param = [
                'name' => $field,
                'in' => 'query',
                'required' => in_array($field, $required),
                'schema' => $fieldSchema,
            ];

            // Thêm description nếu có
            if (isset($fieldSchema['description'])) {
                $param['description'] = $fieldSchema['description'];
            }

            // Thêm example nếu có
            if (isset($fieldSchema['example'])) {
                $param['example'] = $fieldSchema['example'];
            }

            if (!isset($operation['parameters'])) {
                $operation['parameters'] = [];
            }

            $operation['parameters'][] = $param;
        }
    }

    /**
     * Lấy content type từ Api attribute hoặc mặc định
     */
    private function getContentType(Api $apiAttr): string
    {
        if ($apiAttr->contentType) {
            return $apiAttr->contentType;
        }

        return 'application/json'; // Mặc định
    }

    /**
     * Thêm error responses tự động
     */
    private function addErrorResponses(array &$operation): void
    {
        $errorResponses = [
            '400' => 'Bad Request - Validation error',
            '401' => 'Unauthorized',
            '403' => 'Forbidden',
            '404' => 'Not Found',
            '422' => 'Unprocessable Entity - Validation error',
            '500' => 'Internal Server Error',
        ];

        foreach ($errorResponses as $code => $description) {
            if (!isset($operation['responses'][$code])) {
                $operation['responses'][$code] = [
                    'description' => $description,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'message' => ['type' => 'string'],
                                    'errors' => [
                                        'type' => 'object',
                                        'additionalProperties' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string']
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            }
        }
    }

    /**
     * Lấy description cho response code
     */
    private function getResponseDescription(int $code): string
    {
        return match($code) {
            200 => 'Success',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
            default => 'Response',
        };
    }

    /**
     * Export spec ra JSON
     */
    public function toJson(): string
    {
        return json_encode($this->spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
