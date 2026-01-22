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
        
        $collection = $this->convertToPostmanCollection($spec, $tag);
        
        $filename = $tag 
            ? "postman-collection-{$tag}.json" 
            : "postman-collection-all.json";
        
        return response()->json($collection)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Convert OpenAPI spec sang Postman Collection v2.1
     */
    private function convertToPostmanCollection(array $spec, ?string $filterTag = null): array
    {
        $collection = [
            'info' => [
                '_postman_id' => uniqid(),
                'name' => $spec['info']['title'] ?? 'API Collection',
                'description' => $spec['info']['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [],
        ];

        // Group by tags
        $groupedByTag = [];
        
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
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

    /**
     * Convert 1 endpoint sang Postman request
     */
    private function convertToPostmanRequest(string $path, string $method, array $operation, array $spec): array
    {
        $baseUrl = $spec['servers'][0]['url'] ?? '{{base_url}}';
        
        // Replace path parameters: /users/{id} -> /users/:id
        $postmanPath = preg_replace('/\{([^}]+)\}/', ':$1', $path);
        
        $request = [
            'name' => $operation['summary'] ?? ucfirst($method) . ' ' . $path,
            'request' => [
                'method' => strtoupper($method),
                'header' => [
                    [
                        'key' => 'Accept',
                        'value' => 'application/json',
                    ],
                ],
                'url' => [
                    'raw' => $baseUrl . $postmanPath,
                    'host' => [parse_url($baseUrl, PHP_URL_HOST) ?? '{{base_url}}'],
                    'path' => array_filter(explode('/', $postmanPath)),
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

