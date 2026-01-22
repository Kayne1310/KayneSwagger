<?php

namespace Kayne\Swagger\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;

class GenerateApiAnnotations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swagger:gen 
                            {controller : Controller name (e.g., LoginController, UserController, or full class name)}
                            {--force : Force regenerate even if annotation exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate #[Api(...)] annotations for controller methods from routes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $controllerInput = $this->argument('controller');
        $force = $this->option('force');

        // Determine controller class name
        $controllerClass = $this->resolveControllerClass($controllerInput);
        
        if (!$controllerClass) {
            $this->error("Controller not found: {$controllerInput}");
            return Command::FAILURE;
        }

        if (!class_exists($controllerClass)) {
            $this->error("Class does not exist: {$controllerClass}");
            return Command::FAILURE;
        }

        // Get controller file path
        $reflection = new ReflectionClass($controllerClass);
        $filePath = $reflection->getFileName();

        if (!file_exists($filePath)) {
            $this->error("Controller file not found: {$filePath}");
            return Command::FAILURE;
        }

        $this->info("Processing controller: {$controllerClass}");
        $this->info("File: {$filePath}");

        // Find routes for this controller
        $routes = $this->findRoutesForController($controllerClass);
        
        if (empty($routes)) {
            $this->warn("No routes found for controller: {$controllerClass}");
            return Command::FAILURE;
        }

        $this->info("Found " . count($routes) . " route(s)");

        // Read file content
        $fileContent = file_get_contents($filePath);
        $originalContent = $fileContent;

        // Process each route
        $annotationsAdded = 0;
        foreach ($routes as $route) {
            $methodName = $route['method_name'];
            $annotation = $this->generateAnnotation($route);

            // Check if method exists
            if (!$reflection->hasMethod($methodName)) {
                $this->warn("Method '{$methodName}' not found in controller, skipping...");
                continue;
            }

            // Check if annotation already exists
            $methodReflection = $reflection->getMethod($methodName);
            $existingAttributes = $methodReflection->getAttributes(\Kayne\Swagger\Attributes\Api::class);
            
            if (!empty($existingAttributes) && !$force) {
                $this->info("Method '{$methodName}' already has #[Api] annotation, skipping... (use --force to regenerate)");
                continue;
            }

            // Insert annotation before method
            $fileContent = $this->insertAnnotationBeforeMethod(
                $fileContent,
                $methodName,
                $annotation,
                $force && !empty($existingAttributes)
            );

            $annotationsAdded++;
            $this->info("✓ Added annotation for method: {$methodName}");
        }

        // Write file if changed
        if ($fileContent !== $originalContent) {
            file_put_contents($filePath, $fileContent);
            $this->info("\n✓ Successfully generated {$annotationsAdded} annotation(s)");
        } else {
            $this->info("\nNo changes made");
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve controller class name from input
     */
    private function resolveControllerClass(string $input): ?string
    {
        // If it's a file path, extract class name
        if (file_exists($input)) {
            $content = file_get_contents($input);
            if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch) &&
                preg_match('/class\s+(\w+)/', $content, $classMatch)) {
                return $namespaceMatch[1] . '\\' . $classMatch[1];
            }
        }

        // If it's already a full class name, check if exists
        if (class_exists($input)) {
            return $input;
        }

        // Remove .php extension if present
        $input = preg_replace('/\.php$/', '', $input);
        
        // Remove Controller suffix if present (for convenience)
        $baseName = preg_replace('/Controller$/', '', $input);
        $controllerName = $baseName . 'Controller';

        // Try to resolve from common namespaces
        $namespaces = [
            'App\\Http\\Controllers\\Api\\',
            'App\\Http\\Controllers\\',
            'App\\Controllers\\Api\\',
            'App\\Controllers\\',
        ];

        // First try with Controller suffix
        foreach ($namespaces as $namespace) {
            $fullClass = $namespace . $controllerName;
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        // Then try without Controller suffix (in case user provided full name)
        foreach ($namespaces as $namespace) {
            $fullClass = $namespace . $input;
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        // Try with original input (might be full class name)
        foreach ($namespaces as $namespace) {
            $fullClass = $namespace . $input;
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        return null;
    }

    /**
     * Find all routes for a controller
     */
    private function findRoutesForController(string $controllerClass): array
    {
        $routes = [];
        $allRoutes = Route::getRoutes();

        foreach ($allRoutes as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || strpos($action, '@') === false) {
                continue;
            }

            [$controller, $method] = explode('@', $action);

            // Normalize controller name (handle both full namespace and short name)
            // Laravel may return full namespace or just class name depending on how route was defined
            $normalizedController = $this->normalizeControllerName($controller);
            $normalizedTarget = $this->normalizeControllerName($controllerClass);

            if ($normalizedController !== $normalizedTarget) {
                continue;
            }

            // Get route information
            $methods = $route->methods();
            // Filter out HEAD and OPTIONS, prefer POST/GET/PUT/DELETE/PATCH
            $httpMethods = array_filter($methods, function($m) {
                return in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
            });
            $httpMethod = !empty($httpMethods) ? array_values($httpMethods)[0] : (!empty($methods) ? $methods[0] : 'GET');
            
            $uri = $route->uri();
            // Ensure URI starts with / and includes prefix if any
            $path = '/' . ltrim($uri, '/');
            
            $middleware = $route->middleware();
            $name = $route->getName();

            // Extract tag from controller name (e.g., UserController -> User)
            $tag = $this->extractTagFromController($controllerClass);

            // Detect security from middleware
            $security = $this->detectSecurityFromMiddleware($middleware);

            // Use method + path as unique key to avoid duplicates
            $routeKey = $method . ':' . $path;
            
            // If we already have this route, skip or merge (prefer route with more specific path)
            if (isset($routes[$routeKey])) {
                // Keep the one with longer path (more specific) or keep first one
                $existingPath = $routes[$routeKey]['path'];
                if (strlen($path) <= strlen($existingPath)) {
                    continue;
                }
            }

            $routes[$routeKey] = [
                'method_name' => $method,
                'http_method' => $httpMethod,
                'path' => $path,
                'tags' => [$tag],
                'middleware' => $middleware,
                'route_name' => $name,
                'security' => $security,
            ];
        }

        // Return as indexed array
        return array_values($routes);
    }

    /**
     * Normalize controller name for comparison
     * Handles both full namespace and short class name
     */
    private function normalizeControllerName(string $controller): string
    {
        // If it's already a full class name, return as is
        if (class_exists($controller)) {
            return $controller;
        }

        // Try to resolve from common namespaces
        $namespaces = [
            'App\\Http\\Controllers\\Api\\',
            'App\\Http\\Controllers\\',
            'App\\Controllers\\Api\\',
            'App\\Controllers\\',
        ];

        foreach ($namespaces as $namespace) {
            $fullClass = $namespace . $controller;
            if (class_exists($fullClass)) {
                return $fullClass;
            }
        }

        // Return original if can't resolve
        return $controller;
    }

    /**
     * Extract tag from controller class name
     */
    private function extractTagFromController(string $controllerClass): string
    {
        $parts = explode('\\', $controllerClass);
        $className = end($parts);
        
        // Remove "Controller" suffix
        $tag = preg_replace('/Controller$/', '', $className);
        
        return $tag;
    }

    /**
     * Detect security schemes from middleware
     */
    private function detectSecurityFromMiddleware(array $middleware): ?array
    {
        $securityMap = config('swagger.middleware_security_map', [
            'auth:sanctum' => 'bearerAuth',
            'auth:api' => 'bearerAuth',
            'auth' => 'bearerAuth',
            'jwt' => 'bearerAuth',
            'jwt.auth' => 'bearerAuth',
            'token' => 'bearerAuth',
            'sanctum' => 'bearerAuth',
        ]);

        foreach ($middleware as $mw) {
            // Handle string middleware
            if (!is_string($mw)) {
                continue;
            }

            // Exact match first (highest priority)
            if (isset($securityMap[$mw])) {
                return [$securityMap[$mw]];
            }

            // Handle middleware with parameters (e.g., "auth:sanctum", "role:Student")
            $parts = explode(':', $mw);
            $mwName = $parts[0];
            
            // Check exact match for base name
            if (isset($securityMap[$mwName])) {
                return [$securityMap[$mwName]];
            }

            // Check partial match (e.g., "token" in "token.verify")
            foreach ($securityMap as $key => $scheme) {
                if (strpos($mwName, $key) !== false || strpos($mw, $key) !== false) {
                    return [$scheme];
                }
            }
        }

        return null;
    }

    /**
     * Generate annotation string
     */
    private function generateAnnotation(array $route): string
    {
        $parts = [
            "method: '{$route['http_method']}'",
            "path: '{$route['path']}'",
            "tags: ['" . implode("', '", $route['tags']) . "']",
        ];

        // Add security if detected
        if (!empty($route['security'])) {
            $securityStr = "['" . implode("', '", $route['security']) . "']";
            $parts[] = "security: {$securityStr}";
        }

        // Generate summary from method name
        $summary = $this->generateSummaryFromMethodName($route['method_name']);
        if ($summary) {
            $parts[] = "summary: '{$summary}'";
        }

        $annotation = "#[Api(\n        " . implode(",\n        ", $parts) . "\n    )]";

        return $annotation;
    }

    /**
     * Generate summary from method name
     */
    private function generateSummaryFromMethodName(string $methodName): string
    {
        // Common Laravel controller methods
        $methodMap = [
            'index' => 'List all resources',
            'store' => 'Create a new resource',
            'show' => 'Get a specific resource',
            'update' => 'Update a specific resource',
            'destroy' => 'Delete a specific resource',
            'create' => 'Show create form',
            'edit' => 'Show edit form',
        ];

        if (isset($methodMap[$methodName])) {
            return $methodMap[$methodName];
        }

        // Remove common prefixes
        $cleanName = preg_replace('/^(get|post|put|patch|delete|add|remove|update|create|edit|show|list|find|search|submit|assign|import|export)/i', '', $methodName);
        
        // Convert camelCase/PascalCase to Title Case
        // Handle cases like: MyClass, getMenuTree, addStudentToClass
        $words = preg_split('/(?=[A-Z])/', $cleanName ?: $methodName);
        $words = array_filter($words, function($w) { return !empty(trim($w)); });
        
        if (empty($words)) {
            $words = [$methodName];
        }
        
        $title = implode(' ', $words);
        
        // Capitalize first letter of each word
        $title = ucwords(strtolower($title));
        
        // Add verb based on method name prefix
        if (preg_match('/^(get|fetch|retrieve|find|search|list|show)/i', $methodName)) {
            return 'Get ' . lcfirst($title);
        } elseif (preg_match('/^(post|create|add|insert|store|submit|assign)/i', $methodName)) {
            return 'Create ' . lcfirst($title);
        } elseif (preg_match('/^(put|patch|update|edit|modify)/i', $methodName)) {
            return 'Update ' . lcfirst($title);
        } elseif (preg_match('/^(delete|remove|destroy|drop)/i', $methodName)) {
            return 'Delete ' . lcfirst($title);
        }
        
        return $title;
    }

    /**
     * Insert annotation before method in file content
     */
    private function insertAnnotationBeforeMethod(
        string $fileContent,
        string $methodName,
        string $annotation,
        bool $replaceExisting = false
    ): string {
        // Pattern to match method definition
        $methodPattern = '/(\s*)((?:public|protected|private)\s+function\s+' . preg_quote($methodName) . '\s*\()/';
        
        // First, check if method exists
        if (!preg_match($methodPattern, $fileContent, $methodMatches)) {
            return $fileContent;
        }

        $indentation = $methodMatches[1];
        $methodDef = $methodMatches[2];
        
        // Check if there's an Api attribute before this method
        $beforeMethod = substr($fileContent, 0, strpos($fileContent, $methodMatches[0]));
        // Check for Api attribute at the end of beforeMethod (with optional whitespace)
        // Create complete pattern with trailing whitespace check
        $hasExisting = preg_match('/#\[Api\([^)]*(?:\([^)]*\))*\)\]\s*$/s', $beforeMethod);

        if ($hasExisting && !$replaceExisting) {
            // Already has annotation and not forcing replace
            return $fileContent;
        }

        // If replacing, remove existing Api attribute
        if ($hasExisting && $replaceExisting) {
            // Find the start of the Api attribute
            $lines = explode("\n", $fileContent);
            $methodLineIndex = null;
            
            // Find the line with the method
            foreach ($lines as $index => $line) {
                if (preg_match('/\s*(?:public|protected|private)\s+function\s+' . preg_quote($methodName) . '\s*\(/', $line)) {
                    $methodLineIndex = $index;
                    break;
                }
            }
            
            if ($methodLineIndex !== null) {
                // Look backwards for Api attribute
                for ($i = $methodLineIndex - 1; $i >= 0; $i--) {
                    if (preg_match('/#\[Api\(/', $lines[$i])) {
                        // Found Api attribute start, remove it and any continuation lines
                        $startIndex = $i;
                        // Find the end (line with ])
                        for ($j = $startIndex; $j < $methodLineIndex; $j++) {
                            if (preg_match('/\)\]/', $lines[$j])) {
                                // Remove lines from $startIndex to $j
                                array_splice($lines, $startIndex, $j - $startIndex + 1);
                                $fileContent = implode("\n", $lines);
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // Insert new annotation before method
        $replacement = $indentation . $annotation . "\n" . $indentation . $methodDef;
        return preg_replace($methodPattern, $replacement, $fileContent, 1);
    }
}
