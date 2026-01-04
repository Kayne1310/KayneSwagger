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

        // Handle backward compatibility: try to instantiate with all parameters
        try {
            $apiAttr = $attributes[0]->newInstance();
        } catch (\Error $e) {
            // Nếu lỗi do unknown parameter (có thể do version cũ), parse arguments manually
            if (strpos($e->getMessage(), 'Unknown named parameter') !== false) {
                $apiAttr = $this->createApiAttributeFromArguments($attributes[0]);
            } else {
                throw $e;
            }
        }

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
            // Hỗ trợ cả exact match và snake_case <-> camelCase conversion
            $matchedPathParam = $this->matchPathParameter($paramName, $pathParams);
            
            if ($matchedPathParam !== null) {
                $paramSchema = $this->mapPhpTypeToSchema($typeName);
                
                // Sử dụng tên từ path (snake_case) thay vì param name (camelCase)
                $pathParam = [
                    'name' => $matchedPathParam,
                    'in' => 'path',
                    'required' => true,
                    'schema' => $paramSchema,
                    'description' => "The {$matchedPathParam} parameter in path",
                ];
                
                $operation['parameters'][] = $pathParam;
                continue; // Quan trọng: continue để không xử lý như query parameter
            }

            // Còn lại → Query Parameter (chỉ cho primitive types, và không phải path param)
            if ($typeName !== 'Illuminate\Http\Request' && $this->matchPathParameter($paramName, $pathParams) === null) {
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
                // Xác định request source từ Api attribute hoặc auto-detect
                $requestSource = $this->determineRequestSource($apiAttr, $httpMethod, $typeName);
                
                // Query Parameters
                if ($requestSource === 'query') {
                    $this->processFormRequestAsQueryParams($operation, $typeName, $apiAttr);
                }
                // Request Body (JSON hoặc Form)
                elseif (in_array($requestSource, ['body', 'form'])) {
                    // Xác định content type
                    $contentType = $this->getContentType($apiAttr, $requestSource);

                    // Nếu là form-data, cần xử lý đặc biệt cho file upload
                    if ($contentType === 'multipart/form-data' || $contentType === 'application/x-www-form-urlencoded') {
                        $this->processFormDataRequest($operation, $typeName, $apiAttr, $type);
                    } else {
                        // JSON body - dùng schema reference
                        $schemaName = $this->getSchemaName($typeName);

                        // Thêm schema vào components
                        if (!isset($this->spec['components']['schemas'][$schemaName])) {
                            $this->spec['components']['schemas'][$schemaName] = SchemaGenerator::generate($typeName);
                        }

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
        }

        // Xóa key parameters nếu rỗng
        if (empty($operation['parameters'])) {
            unset($operation['parameters']);
        }
    }

    /**
     * Tạo Api attribute từ arguments (backward compatibility)
     * Xử lý trường hợp package cũ không có requestSource parameter
     */
    private function createApiAttributeFromArguments($attribute): Api
    {
        $args = $attribute->getArguments();
        
        // Map arguments theo thứ tự constructor
        // method, path, tags, summary, description, responseType, responseCode, security, contentType, requestSource
        return new Api(
            method: $args['method'] ?? $args[0] ?? '',
            path: $args['path'] ?? $args[1] ?? '',
            tags: $args['tags'] ?? $args[2] ?? [],
            summary: $args['summary'] ?? $args[3] ?? null,
            description: $args['description'] ?? $args[4] ?? null,
            responseType: $args['responseType'] ?? $args[5] ?? null,
            responseCode: $args['responseCode'] ?? $args[6] ?? null,
            security: $args['security'] ?? $args[7] ?? null,
            contentType: $args['contentType'] ?? $args[8] ?? null,
            requestSource: $args['requestSource'] ?? $args[9] ?? null,
        );
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
     * Match method parameter với path parameter
     * Hỗ trợ exact match và snake_case <-> camelCase conversion
     * 
     * @param string $paramName Method parameter name (camelCase hoặc snake_case)
     * @param array $pathParams Path parameters từ URI (snake_case)
     * @return string|null Tên path parameter nếu match, null nếu không match
     */
    private function matchPathParameter(string $paramName, array $pathParams): ?string
    {
        // 1. Exact match
        if (in_array($paramName, $pathParams)) {
            return $paramName;
        }

        // 2. Convert camelCase -> snake_case và match
        $snakeCase = $this->camelToSnake($paramName);
        if (in_array($snakeCase, $pathParams)) {
            return $snakeCase;
        }

        // 3. Convert snake_case -> camelCase và match
        $camelCase = $this->snakeToCamel($paramName);
        if (in_array($camelCase, $pathParams)) {
            return $camelCase;
        }

        // 4. Check từng path param xem có match không (reverse conversion)
        foreach ($pathParams as $pathParam) {
            $pathParamCamel = $this->snakeToCamel($pathParam);
            if ($pathParamCamel === $paramName) {
                return $pathParam;
            }
            
            $paramNameSnake = $this->camelToSnake($paramName);
            if ($paramNameSnake === $pathParam) {
                return $pathParam;
            }
        }

        return null;
    }

    /**
     * Convert camelCase to snake_case
     */
    private function camelToSnake(string $camel): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $camel));
    }

    /**
     * Convert snake_case to camelCase
     */
    private function snakeToCamel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
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
     * Xác định request source từ Api attribute hoặc auto-detect
     */
    private function determineRequestSource(Api $apiAttr, string $httpMethod, string $typeName): string
    {
        // 1. Ưu tiên: requestSource từ Api attribute
        if ($apiAttr->requestSource) {
            return $apiAttr->requestSource;
        }

        // 2. Detect từ contentType
        if ($apiAttr->contentType) {
            if ($apiAttr->contentType === 'multipart/form-data' || $apiAttr->contentType === 'application/x-www-form-urlencoded') {
                return 'form';
            }
            return 'body';
        }

        // 3. Auto-detect từ HTTP method và type
        if (in_array($httpMethod, ['get', 'head', 'options']) && is_subclass_of($typeName, FormRequestDto::class)) {
            return 'query';
        }

        if (in_array($httpMethod, ['post', 'put', 'patch'])) {
            return 'body';
        }

        return 'body'; // Mặc định
    }

    /**
     * Lấy content type từ Api attribute hoặc mặc định
     */
    private function getContentType(Api $apiAttr, ?string $requestSource = null): string
    {
        // Nếu có contentType trong attribute, dùng nó
        if ($apiAttr->contentType) {
            return $apiAttr->contentType;
        }

        // Nếu requestSource là form, dùng multipart/form-data
        if ($requestSource === 'form') {
            return 'multipart/form-data';
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
     * Xử lý Form Data request (multipart/form-data hoặc x-www-form-urlencoded)
     * Tự động detect file fields và generate schema đúng
     */
    private function processFormDataRequest(array &$operation, string $formRequestClass, Api $apiAttr, $type): void
    {
        $schema = SchemaGenerator::generate($formRequestClass);
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        // Tạo schema cho form data với encoding cho file fields
        $formSchema = [
            'type' => 'object',
            'properties' => [],
            'required' => $required ?: []
        ];

        $encoding = [];

        foreach ($properties as $field => $fieldSchema) {
            $formSchema['properties'][$field] = $fieldSchema;

            // Nếu là file field (format: binary), thêm encoding
            if (isset($fieldSchema['format']) && $fieldSchema['format'] === 'binary') {
                $encoding[$field] = [
                    'contentType' => $this->detectFileContentType($fieldSchema)
                ];
            }
        }

        $contentType = $this->getContentType($apiAttr, 'form');

        $requestBody = [
            'required' => !$type->allowsNull(),
            'content' => [
                $contentType => [
                    'schema' => $formSchema
                ]
            ]
        ];

        // Thêm encoding nếu có file fields
        if (!empty($encoding)) {
            $requestBody['content'][$contentType]['encoding'] = $encoding;
        }

        $operation['requestBody'] = $requestBody;
    }

    /**
     * Detect content type cho file field
     */
    private function detectFileContentType(array $fieldSchema): string
    {
        // Nếu có mimes trong description, parse nó
        if (isset($fieldSchema['description'])) {
            // Tìm mimes:jpg,png hoặc Allowed types: jpg, png
            if (preg_match('/mimes?:([^,\s]+)/i', $fieldSchema['description'], $matches)) {
                $mime = trim($matches[1]);
                return $this->mapMimeToContentType($mime);
            }
            if (preg_match('/Allowed types:\s*([^\.]+)/i', $fieldSchema['description'], $matches)) {
                $mimes = array_map('trim', explode(',', $matches[1]));
                if (!empty($mimes)) {
                    return $this->mapMimeToContentType($mimes[0]);
                }
            }
        }

        // Kiểm tra xem có phải image không
        if (isset($fieldSchema['description']) && stripos($fieldSchema['description'], 'image') !== false) {
            return 'image/*';
        }

        return 'application/octet-stream'; // Mặc định
    }

    /**
     * Map file extension/mime sang content type
     */
    private function mapMimeToContentType(string $mime): string
    {
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
        ];

        $mime = strtolower(trim($mime));
        return $map[$mime] ?? 'application/octet-stream';
    }

    /**
     * Export spec ra JSON
     */
    public function toJson(): string
    {
        return json_encode($this->spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
