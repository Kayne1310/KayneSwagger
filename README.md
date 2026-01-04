# Kayne-Swagger

A minimal, clean Swagger/OpenAPI documentation generator for Laravel using strongly-typed DTOs.

##  Features

-  **Type-Safe DTOs**: Use PHP 8.1+ type hints instead of doc comments
-  **FormRequestDto Support**: Seamlessly integrate existing FormRequest with Swagger
-  **Minimal Code**: 80% less boilerplate than l5-swagger
-  **Auto-Validation**: Automatic validation from type definitions
-  **Auto Security Detection**: Automatically detect JWT/Bearer token from route middleware
-  **Form Body Support**: Support form-data and x-www-form-urlencoded like .NET
-  **Query Parameters**: Auto-convert FormRequestDto to query params in GET requests
-  **Nested Arrays**: Full support for nested arrays and array items
-  **Clean Syntax**: Modern PHP attributes instead of verbose annotations
-  **DRY Principle**: Define once, use everywhere
-  **Like .NET Swagger**: Fast and efficient like .NET Core

##  Installation

```bash
composer require kayne/swagger
```

The package will auto-register via Laravel's package discovery.

See [INSTALLATION.md](INSTALLATION.md) for detailed installation guide.

##  Quick Start

### 1. Create a DTO (Optional annotations!)

```php
<?php

namespace App\Dtos;

use Kayne\Swagger\BaseDto;

class CreateUserDto extends BaseDto
{
    public string $name;   // Just type hints!
    public string $email;
    public ?int $age = null;
}
```

### 2. Use in Controller (Only 3 required fields!)

```php
<?php

namespace App\Http\Controllers;

use App\Dtos\CreateUserDto;
use Kayne\Swagger\Attributes\Api;

class UserController extends Controller
{
    #[Api(
        method: 'POST',
        path: '/api/users',
        tags: ['Users']
    )]
    public function store(CreateUserDto $dto)
    {
        // Auto-detected: request body, validation, response
        return response()->json($dto->toArray());
    }
}
```

### 3. Register Routes with Middleware

```php
Route::middleware(['dto'])->group(function () {
    Route::post('/api/users', [UserController::class, 'store']);
});
```

### 4. View Documentation

Visit: `http://your-app.test/api/documentation`

##  Advanced Features

### FormRequestDto - Use Existing FormRequest

```php
use Kayne\Swagger\FormRequestDto;

class AssessmentSetIndexRequest extends FormRequestDto
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

// GET request → Auto-convert to query parameters
#[Api(method: 'GET', path: '/api/assessment-sets', tags: ['Assessment'])]
public function index(AssessmentSetIndexRequest $request) { }
```

### Auto Security Detection from Route Group

```php
// routes/api.php
Route::group(['middleware' => ['token']], function () {
    Route::get('/menu-tree', [MenuController::class, 'getMenuTree']);
});

// Controller - No security parameter needed!
#[Api(method: 'GET', path: '/api/menu-tree', tags: ['Menu'])]
public function getMenuTree() { }
```

→ Automatically adds `bearerAuth` security from middleware `token`!

### Form Body Support

```php
#[Api(
    method: 'POST',
    path: '/api/upload',
    tags: ['Upload'],
    contentType: 'multipart/form-data' // or 'application/x-www-form-urlencoded'
)]
public function upload(UploadDto $dto) { }
```

### Array Items Support

```php
class DestroyRangeRequest extends FormRequestDto
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'], // Array items
        ];
    }
}
```

##  Documentation

See [USAGE.md](USAGE.md) for detailed documentation (Vietnamese).

##  Comparison with l5-swagger

### l5-swagger (Old way)
```php
/**
 * @OA\Post(
 *     path="/api/users",
 *     tags={"Users"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"name","email"},
 *             @OA\Property(property="name", type="string", minLength=3),
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="age", type="integer", minimum=18)
 *         )
 *     ),
 *     @OA\Response(response="201", description="Created")
 * )
 */
public function store(Request $request) {
    $validated = $request->validate([
        'name' => 'required|string|min:3',
        'email' => 'required|email',
        'age' => 'nullable|integer|min:18'
    ]);
}
```

### Kayne-Swagger (New way)
```php
// DTO - No annotations needed!
class CreateUserDto extends BaseDto {
    public string $name;
    public string $email;
    public ?int $age = null;
}

// Controller - Only 3 fields!
#[Api(method: 'POST', path: '/api/users', tags: ['Users'])]
public function store(CreateUserDto $dto) { }
```

**93% less code! **

##  Why Kayne-Swagger?

-  **Minimal**: Only 3 required fields (method, path, tags)
-  **Smart Detection**: Auto-detect request body, path params, query params, security
-  **FormRequest Support**: Use existing FormRequest without rewriting
-  **Auto Security**: Automatically detect JWT/Bearer token from route middleware
-  **Form Body**: Support form-data and x-www-form-urlencoded like .NET
-  **Less Clutter**: Annotations are optional!
-  **Type Safety**: Leverages PHP's type system
-  **Auto-Validation**: Validation rules from types
-  **Modern PHP**: Uses PHP 8.1+ attributes
-  **DRY**: Single source of truth for data structure
-  **Fast**: Like .NET's Swagger generation

##  Available Property Attributes

```php
#[PropSmart Parameter Detection

Kayne-Swagger automatically detects parameters like .NET:

```php
// Path parameter - auto-detected from {id}
#[Api(method: 'GET', path: '/api/users/{id}', tags: ['Users'])]
public function show(int $id) { }

// Query parameters - auto-detected
#[Api(method: 'GET', path: '/api/users', tags: ['Users'])]
public function index(?int $page = 1, ?int $limit = 10) { }

##  Api Attributes (Only 3 Required!)

```php
#[Api(
    method: 'POST|GET|PUT|PATCH|DELETE',  // Required
    path: '/api/path',                    // Required
    tags: ['Tag1', 'Tag2'],               // Required

    // All below are OPTIONAL (auto-generated if not provided)
    summary: 'Short description',         // Optional
    description: 'Detailed description',  // Optional
    responseType: ResponseDto::class,     // Optional
    responseCode: 201,                    // Optional (default: 200)
    security: ['bearerAuth'],            // Optional (auto-detect from middleware)
    contentType: 'application/json'      // Optional (default: 'application/json')
)]
```

##  Property Attributes (All Optional!)

```php
#[Property(
    description: "Field description",     // Optional
    example: "example value",             // Optional
    format: "email|password|date",        // Optional
    minimum: 0,                           // Optional
    maximum: 100,                         // Optional
    minLength: 3,                         // Optional
    maxLength: 255,                       // Optional
    pattern: "/regex/",                   // Optional
    enum: ["option1", "option2"]          // Optional
)]
```

**You can even skip all Property attributes and just use type hints!**

##  Configuration

Publish config (optional):

```bash
php artisan vendor:publish --tag=swagger-config
```

Configure in `.env`:

```env
SWAGGER_TITLE="My API"
SWAGGER_VERSION="2.0.0"
SWAGGER_ROUTE="api/documentation"
SWAGGER_ENABLED=true
SWAGGER_AUTO_DETECT_SECURITY=true
SWAGGER_GLOBAL_SECURITY=bearerAuth
```

### Security Configuration

Auto-detect middleware names and map to security schemes:

```php
// config/swagger.php
'middleware_security_map' => [
    'token' => 'bearerAuth',      // Auto-detect from route group
    'auth' => 'bearerAuth',
    'auth:sanctum' => 'bearerAuth',
    'jwt' => 'bearerAuth',
],
```

##  Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

##  License

MIT License

##  Credits

Inspired by .NET's minimal API approach and frustrated with l5-swagger's verbosity.

---

Made with ❤️ for Laravel developers who love clean code
