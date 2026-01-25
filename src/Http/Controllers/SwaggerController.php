<?php

namespace Kayne\Swagger\Http\Controllers;

use Illuminate\Routing\Controller;
use Kayne\Swagger\SwaggerGenerator;
use Illuminate\Http\Request;

class SwaggerController extends Controller
{
    /**
     * Hiển thị Swagger UI
     */
    public function ui()
    {
        return view('swagger::ui');
    }

    /**
     * API spec JSON
     */
    public function spec()
    {
        $generator = new SwaggerGenerator();
        $spec = $generator->generate();

        return response()->json($spec);
    }

    /**
     * Export Postman Collection
     */
    public function exportPostman(Request $request)
    {
        $generator = new SwaggerGenerator();
        $spec = $generator->generate();
        
        $tag = $request->get('tag'); // Lọc theo tag nếu có
        $path = $request->get('path'); // Export 1 endpoint nếu có
        $method = $request->get('method'); // GET/POST/...
        
        $collection = $this->convertToPostmanCollection(
            $spec,
            is_string($tag) ? $tag : null,
            is_string($path) ? $path : null,
            is_string($method) ? $method : null
        );
        
        $filename = $this->buildPostmanFilename(
            is_string($tag) ? $tag : null,
            is_string($path) ? $path : null,
            is_string($method) ? $method : null
        );
        
        return response()->json($collection)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Export Postman Environment
     */
    public function exportPostmanEnvironment()
    {
        $baseUrlVar = (string) config('swagger.postman.base_url_variable', 'base_url');
        $tokenVar = (string) config('swagger.postman.token_variable', 'token');
        $tokenValue = (string) config('swagger.postman.token', '');
        $envName = (string) config('swagger.postman.environment_name', 'Swagger Environment');
        $protocolVar = (string) config('swagger.postman.protocol_variable', 'protocol');
        $hostVar = (string) config('swagger.postman.host_variable', 'host');
        $portVar = (string) config('swagger.postman.port_variable', 'port');

        $baseUrlValue = (string) config('swagger.postman.base_url', config('app.url', 'http://localhost:8000'));
        $protocolValue = (string) config('swagger.postman.protocol', 'http');
        $hostValue = (string) config('swagger.postman.host', 'localhost');
        $portValue = (string) config('swagger.postman.port', '8000');

        $environment = [
            'id' => uniqid(),
            'name' => $envName,
            'values' => [
                [
                    'key' => $baseUrlVar,
                    'value' => $baseUrlValue,
                    'enabled' => true,
                ],
                [
                    'key' => $protocolVar,
                    'value' => $protocolValue,
                    'enabled' => true,
                ],
                [
                    'key' => $hostVar,
                    'value' => $hostValue,
                    'enabled' => true,
                ],
                [
                    'key' => $portVar,
                    'value' => $portValue,
                    'enabled' => true,
                ],
                [
                    'key' => $tokenVar,
                    'value' => $tokenValue,
                    'enabled' => true,
                ],
            ],
            '_postman_variable_scope' => 'environment',
            '_postman_exported_at' => gmdate('c'),
            '_postman_exported_using' => 'KayneSwagger',
        ];

        return response()->json($environment)
            ->header('Content-Disposition', 'attachment; filename="postman-environment.json"');
    }

    /**
     * Export Postman Globals (workspace-level variables)
     */
    public function exportPostmanGlobals()
    {
        $baseUrlVar = (string) config('swagger.postman.base_url_variable', 'base_url');
1        $baseUrlValue = (string) config('swagger.postman.base_url', '');
        $tokenVar = (string) config('swagger.postman.token_variable', 'token');
        $tokenValue = (string) config('swagger.postman.token', '');

        $protocolVar = (string) config('swagger.postman.protocol_variable', 'protocol');
        $hostVar = (string) config('swagger.postman.host_variable', 'host');
        $portVar = (string) config('swagger.postman.port_variable', 'port');
        $portSuffixVar = (string) config('swagger.postman.port_suffix_variable', 'port_suffix');
        $basePathVar = (string) config('swagger.postman.base_path_variable', 'base_path');

        // Derive helper defaults from base_url (safe even if base_url is empty)
        $protocolValue = (string) (parse_url($baseUrlValue, PHP_URL_SCHEME) ?: '');
        $hostValue = (string) (parse_url($baseUrlValue, PHP_URL_HOST) ?: '');
        $portValue = (string) (parse_url($baseUrlValue, PHP_URL_PORT) ?: '');
        $portSuffixValue = $portValue !== '' ? (':' . $portValue) : '';
        $basePathValue = trim((string) (parse_url($baseUrlValue, PHP_URL_PATH) ?: ''), '/');

        $globals = [
            'id' => uniqid(),
            'values' => [
                [
                    'key' => $baseUrlVar,
                    'value' => $baseUrlValue,
                    'enabled' => true,
                ],
                [
                    'key' => $protocolVar,
                    'value' => $protocolValue,
                    'enabled' => true,
                ],
                [
                    'key' => $hostVar,
                    'value' => $hostValue,
                    'enabled' => true,
                ],
                [
                    'key' => $portVar,
                    'value' => $portValue,
                    'enabled' => true,
                ],
                [
                    'key' => $portSuffixVar,
                    'value' => $portSuffixValue,
                    'enabled' => true,
                ],
                [
                    'key' => $basePathVar,
                    'value' => $basePathValue,
                    'enabled' => true,
                ],
                [
                    'key' => $tokenVar,
                    'value' => $tokenValue,
                    'enabled' => true,
                ],
            ],
            '_postman_variable_scope' => 'globals',
            '_postman_exported_at' => gmdate('c'),
            '_postman_exported_using' => 'KayneSwagger',
        ];

        return response()->json($globals)
            ->header('Content-Disposition', 'attachment; filename="postman-globals.json"');
    }

    /**
     * Convert OpenAPI spec sang Postman Collection v2.1
     */
    private function convertToPostmanCollection(array $spec, ?string $filterTag = null, ?string $filterPath = null, ?string $filterMethod = null): array
    {
        $baseUrlVar = (string) config('swagger.postman.base_url_variable', 'base_url');
        $protocolVar = (string) config('swagger.postman.protocol_variable', 'protocol');
        $hostVar = (string) config('swagger.postman.host_variable', 'host');
        $portVar = (string) config('swagger.postman.port_variable', 'port');
        $portSuffixVar = (string) config('swagger.postman.port_suffix_variable', 'port_suffix');
        $basePathVar = (string) config('swagger.postman.base_path_variable', 'base_path');
        $tokenVar = (string) config('swagger.postman.token_variable', 'token');
        // base_url/token are intended to be GLOBAL variables (Postman Globals).
        // Do NOT define them as collection variables, otherwise they override globals.

        $collection = [
            'info' => [
                '_postman_id' => uniqid(),
                'name' => $spec['info']['title'] ?? 'API Collection',
                'description' => $spec['info']['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            // Keep helper globals synced from {{base_url}} so Postman UI doesn't show blank URL.
            'event' => [
                [
                    'listen' => 'prerequest',
                    'script' => [
                        'type' => 'text/javascript',
                        'exec' => [
                            "(function () {",
                            "  var baseUrl = pm.globals.get('" . $baseUrlVar . "');",
                            "  if (!baseUrl) return;",
                            "  try {",
                            "    var u = new URL(baseUrl);",
                            "    pm.globals.set('" . $protocolVar . "', (u.protocol || 'http:').replace(':',''));",
                            "    pm.globals.set('" . $hostVar . "', u.hostname || 'localhost');",
                            "    pm.globals.set('" . $portVar . "', u.port || '');",
                            "    pm.globals.set('" . $portSuffixVar . "', u.port ? (':' + u.port) : '');",
                            "    pm.globals.set('" . $basePathVar . "', (u.pathname || '').replace(/^\\/+|\\/+$/g, ''));",
                            "  } catch (e) {",
                            "    // ignore invalid base_url",
                            "  }",
                            "})();",
                        ],
                    ],
                ],
            ],
            // Global auth (applies to all requests)
            // Token value is stored in collection variable {{token}}
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    [
                        'key' => 'token',
                        'value' => '{{' . $tokenVar . '}}',
                        'type' => 'string',
                    ],
                ],
            ],
            'item' => [],
        ];

        // Group by tags
        $groupedByTag = [];
        
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            if ($filterPath !== null && $path !== $filterPath) {
                continue;
            }
            foreach ($methods as $method => $operation) {
                if ($filterMethod !== null && strtolower((string) $method) !== strtolower($filterMethod)) {
                    continue;
                }
                $tags = $operation['tags'] ?? ['Default'];
                
                foreach ($tags as $tag) {
                    // Filter by tag nếu có
                    if ($filterTag && $tag !== $filterTag) {
                        continue;
                    }
                    
                    if (!isset($groupedByTag[$tag])) {
                        $groupedByTag[$tag] = [];
                    }
                    
                    $groupedByTag[$tag][] = $this->convertToPostmanRequest(
                        $path, 
                        $method, 
                        $operation, 
                        $spec
                    );
                }
            }
        }

        // Convert to Postman folder structure
        foreach ($groupedByTag as $tag => $requests) {
            $collection['item'][] = [
                'name' => $tag,
                'item' => $requests,
            ];
        }

        return $collection;
    }

    private function buildPostmanFilename(?string $tag = null, ?string $path = null, ?string $method = null): string
    {
        if ($path !== null) {
            $m = $method ? strtolower($method) : 'all';
            $safePath = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', trim($path));
            $safePath = trim($safePath, '-');
            $safePath = $safePath !== '' ? $safePath : 'endpoint';
            return "postman-{$m}-{$safePath}.json";
        }

        if ($tag) {
            $safeTag = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $tag);
            $safeTag = trim($safeTag, '-');
            return $safeTag !== '' ? "postman-collection-{$safeTag}.json" : "postman-collection-tag.json";
        }

        return "postman-collection-all.json";
    }

    /**
     * Convert 1 endpoint sang Postman request
     */
    private function convertToPostmanRequest(string $path, string $method, array $operation, array $spec): array
    {
        $baseUrlVar = (string) config('swagger.postman.base_url_variable', 'base_url');
        $protocolVar = (string) config('swagger.postman.protocol_variable', 'protocol');
        $hostVar = (string) config('swagger.postman.host_variable', 'host');
        $portVar = (string) config('swagger.postman.port_variable', 'port');
        $basePathVar = (string) config('swagger.postman.base_path_variable', 'base_path');
        
        // Replace path parameters: /users/{id} -> /users/:id
        $postmanPath = preg_replace('/\{([^}]+)\}/', ':$1', $path);

        $endpointSegments = array_values(array_filter(explode('/', trim($postmanPath, '/'))));

        // Common Laravel pattern: routes start with /api/...
        // If your Postman base_url includes "/api", strip leading "api" from endpoint path.
        $segmentsWithoutBase = $endpointSegments;
        if (!empty($segmentsWithoutBase) && $segmentsWithoutBase[0] === 'api') {
            $segmentsWithoutBase = array_slice($segmentsWithoutBase, 1);
        }

        $rawUrl = rtrim('{{' . $baseUrlVar . '}}', '/') . '/' . implode('/', $segmentsWithoutBase);

        // Use helper globals for URL components so Postman UI renders URL correctly
        $hostParts = ['{{' . $hostVar . '}}'];
        $pathSegments = array_merge(
            ['{{' . $basePathVar . '}}'],
            $segmentsWithoutBase
        );
        // Remove empty base_path segment if base_path is empty
        $pathSegments = array_values(array_filter($pathSegments, fn ($s) => $s !== ''));
        
        $request = [
            'name' => !empty($operation['summary'])
                ? $operation['summary']
                : $this->generatePostmanRequestName($method, $path),
            'request' => [
                'method' => strtoupper($method),
                'header' => [
                    [
                        'key' => 'Accept',
                        'value' => 'application/json',
                    ],
                ],
                'url' => [
                    'raw' => $rawUrl,
                    'protocol' => '{{' . $protocolVar . '}}',
                    'host' => $hostParts,
                    'port' => '{{' . $portVar . '}}',
                    'path' => $pathSegments,
                ],
            ],
        ];

        // Add description
        if (!empty($operation['description'])) {
            $request['request']['description'] = $operation['description'];
        }

        // Add path/query parameters
        if (!empty($operation['parameters'])) {
            $pathVariables = [];
            $queryParams = [];
            
            foreach ($operation['parameters'] as $param) {
                if ($param['in'] === 'path') {
                    $pathVariables[] = [
                        'key' => $param['name'],
                        'value' => '',
                        'description' => $param['description'] ?? '',
                    ];
                } elseif ($param['in'] === 'query') {
                    $queryParams[] = [
                        'key' => $param['name'],
                        'value' => '',
                        'description' => $param['description'] ?? '',
                        'disabled' => !($param['required'] ?? false),
                    ];
                }
            }
            
            if (!empty($pathVariables)) {
                $request['request']['url']['variable'] = $pathVariables;
            }
            
            if (!empty($queryParams)) {
                $request['request']['url']['query'] = $queryParams;
            }
        }

        // Add request body
        if (!empty($operation['requestBody'])) {
            $content = $operation['requestBody']['content'] ?? [];
            
            if (isset($content['application/json'])) {
                $request['request']['header'][] = [
                    'key' => 'Content-Type',
                    'value' => 'application/json',
                ];
                
                $schema = $content['application/json']['schema'] ?? [];
                $example = $this->generateExampleFromSchema($schema, $spec);
                
                $request['request']['body'] = [
                    'mode' => 'raw',
                    'raw' => json_encode($example, JSON_PRETTY_PRINT),
                    'options' => [
                        'raw' => [
                            'language' => 'json',
                        ],
                    ],
                ];
            }
        }

        return $request;
    }

    private function postmanNeedsBearerAuth(array $operation, array $spec): bool
    {
        // Prefer operation-level security; fallback to global spec security
        $security = $operation['security'] ?? ($spec['security'] ?? null);
        if ($security === null) {
            return false;
        }

        // OpenAPI: security can be [] (explicitly no auth)
        if (is_array($security) && $security === []) {
            return false;
        }

        // If bearerAuth is present in any security requirement object
        foreach ((array) $security as $req) {
            if (is_array($req) && array_key_exists('bearerAuth', $req)) {
                return true;
            }
        }

        return false;
    }

    private function generatePostmanRequestName(string $method, string $path): string
    {
        $verb = match (strtoupper($method)) {
            'GET' => 'Get',
            'POST' => 'Create',
            'PUT', 'PATCH' => 'Update',
            'DELETE' => 'Delete',
            default => strtoupper($method),
        };

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $segments = array_values(array_filter($segments, function ($seg) {
            if ($seg === 'api') return false;
            if (preg_match('/^v\d+$/i', $seg)) return false;
            if (preg_match('/^\{[^}]+\}$/', $seg)) return false;
            return true;
        }));

        $tail = array_slice($segments, -3);
        $namePart = implode(' ', array_map(function ($seg) {
            $seg = str_replace(['-', '_'], ' ', $seg);
            $seg = preg_replace('/\s+/', ' ', trim($seg));
            return $seg;
        }, $tail));

        $namePart = trim($namePart);
        return $namePart !== '' ? ($verb . ' ' . ucwords($namePart)) : ($verb . ' ' . $path);
    }

    /**
     * Generate example data từ schema
     */
    private function generateExampleFromSchema(array $schema, array $spec): mixed
    {
        // Handle $ref
        if (isset($schema['$ref'])) {
            $refPath = str_replace('#/components/schemas/', '', $schema['$ref']);
            $schema = $spec['components']['schemas'][$refPath] ?? [];
        }

        $type = $schema['type'] ?? 'object';
        
        return match($type) {
            'object' => $this->generateObjectExample($schema, $spec),
            'array' => [$this->generateExampleFromSchema($schema['items'] ?? [], $spec)],
            'string' => $schema['example'] ?? 'string',
            'integer' => $schema['example'] ?? 0,
            'number' => $schema['example'] ?? 0.0,
            'boolean' => $schema['example'] ?? true,
            default => null,
        };
    }

    /**
     * Generate object example
     */
    private function generateObjectExample(array $schema, array $spec): array
    {
        $example = [];
        
        foreach ($schema['properties'] ?? [] as $propName => $propSchema) {
            $example[$propName] = $this->generateExampleFromSchema($propSchema, $spec);
        }
        
        return $example;
    }
}

