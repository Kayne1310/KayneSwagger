# Kayne-Swagger - Hướng dẫn sử dụng

## Cài đặt

```bash
composer require kayne/swagger
```

Publish config (optional):
```bash
php artisan vendor:publish --tag=swagger-config
```

## 1. FormRequestDto - Sử dụng FormRequest với Swagger

Nếu bạn đã có sẵn FormRequest và muốn tích hợp với Swagger, sử dụng `FormRequestDto` thay vì `FormRequest`:

### Cách chuyển đổi FormRequest sang FormRequestDto

**Trước (FormRequest thông thường):**
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssessmentResultHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assessment_sets' => ['required', 'array', 'min:1'],
            'assessment_sets.*.assessment_set_id' => ['required', 'string'],
            'assessment_sets.*.class_assessment_set_id' => ['required', 'string'],
        ];
    }
}
```

**Sau (FormRequestDto - Tương thích Swagger):**
```php
<?php

namespace App\Http\Requests;

use Kayne\Swagger\FormRequestDto;
use Kayne\Swagger\Attributes\Property;

class AssessmentResultHistoryRequestDto extends FormRequestDto
{
    // Optional: Thêm Property attributes để bổ sung metadata cho Swagger
    #[Property(description: "Danh sách các assessment sets")]
    public array $assessment_sets;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Laravel validation rules - Swagger sẽ tự động đọc từ đây!
     * Hỗ trợ nested arrays: assessment_sets.*.field
     * Có thể thêm 'description' => '...' vào rules array để set description cho Swagger
     */
    public function rules(): array
    {
        return [
            'assessment_sets' => ['required', 'array', 'min:1', 'description' => 'Danh sách các assessment sets'],
            'assessment_sets.*.assessment_set_id' => ['required', 'string', 'description' => 'ID của bộ đề'],
            'assessment_sets.*.class_assessment_set_id' => ['required', 'string'],
        ];
    }
}
```

### Sử dụng trong Controller

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssessmentResultHistoryRequestDto;
use Kayne\Swagger\Attributes\Api;

class AssessmentController extends Controller
{
    #[Api(
        method: 'POST',
        path: '/api/assessment-results/history',
        summary: 'Lưu lịch sử kết quả đánh giá',
        tags: ['Assessments']
    )]
    public function storeHistory(AssessmentResultHistoryRequestDto $request)
    {
        // Request đã được validate tự động
        $validated = $request->validated();
        
        // Your logic here...
        return response()->json(['success' => true]);
    }
}
```

### Tính năng

- Tự động generate Swagger schema từ `rules()` - CHỈ CẦN rules() LÀ ĐỦ!
- Hỗ trợ nested arrays (ví dụ: `assessment_sets.*.field`)
- Giữ nguyên tất cả tính năng của FormRequest (validation, authorization, messages, etc.)
- KHÔNG CẦN define properties - Swagger đọc từ rules() tự động
- Optional Property attributes để bổ sung metadata (description, example, etc.) - chỉ khi cần

## 2. Query Parameters từ FormRequestDto (GET Request)

FormRequestDto trong GET request sẽ tự động được convert thành Query Parameters:

```php
// Controller
#[Api(
    method: 'GET',
    path: '/api/assessment-sets',
    tags: ['Assessment']
)]
public function index(AssessmentSetIndexRequest $request) { }
```

```php
// AssessmentSetIndexRequest
class AssessmentSetIndexRequest extends FormRequestDto
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'description' => 'Page number'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100', 'description' => 'Items per page'],
            'status' => ['nullable', 'integer', 'in:0,1', 'description' => 'Status filter'], // Dropdown: 0 hoặc 1
            'is_active' => ['nullable', 'in:true,false', 'description' => 'Active status'], // Dropdown: true/false
        ];
    }
}
```

Swagger sẽ tự động generate query parameters: `?page=1&per_page=10&status=0&is_active=true`

**Lưu ý:**
- Có thể thêm `'description' => '...'` vào rules array để set description cho Swagger (không cần Property attribute)
- Sử dụng `in:value1,value2` để tạo dropdown trong Swagger UI (chỉ cho query parameters)
- `in:true,false` sẽ tự động tạo boolean dropdown

### Array Items Support

```php
class AssessmentSetDestroyRangeRequest extends FormRequestDto
{
    #[Property(
        description: "Array of assessment set IDs to delete",
        example: ["1", "2", "3"]
    )]
    public array $ids;

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'], // Array items
        ];
    }
}
```

### Nested Arrays

```php
public function rules(): array
{
    return [
        'assessment_sets' => ['required', 'array', 'min:1'],
        'assessment_sets.*.assessment_set_id' => ['required', 'string'],
        'assessment_sets.*.class_assessment_set_id' => ['required', 'string'],
    ];
}
```

Swagger sẽ tự động generate schema phù hợp với cấu trúc nested này.

## 3. Security / Authentication (JWT Bearer Token)

Package hỗ trợ đầy đủ JWT Bearer token authentication trong Swagger với 3 cách:

### Cách 1: Tự động detect từ Middleware (Khuyến nghị)

Không cần làm gì! Swagger sẽ tự động detect từ route middleware:

```php
// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
});

// Controller - Không cần thêm gì!
#[Api(
    method: 'GET',
    path: '/api/users',
    tags: ['Users']
)]
public function index() { }
```

Swagger tự động thêm `bearerAuth` security cho endpoint này.

**Middleware được hỗ trợ tự động:**
- `token` → `bearerAuth` (từ route group)
- `auth` → `bearerAuth`
- `auth:sanctum` → `bearerAuth`
- `auth:api` → `bearerAuth`
- `jwt` → `bearerAuth`
- `jwt.auth` → `bearerAuth`
- `sanctum` → `bearerAuth`

**Ví dụ với Route Group:**
```php
// routes/api.php
Route::group(['prefix' => 'common', 'middleware' => ['init_request', 'token']], function () {
    Route::get('/get-menu-tree', [TbCoreProgramMenuController::class, 'getMenuTree']);
});

// Controller - KHÔNG CẦN thêm security parameter!
#[Api(
    method: 'GET',
    path: '/api/common/get-menu-tree',
    tags: ['Common']
)]
public function getMenuTree() { }
```

Swagger tự động thêm `bearerAuth` security cho endpoint này.

### Cách 2: Chỉ định Security trong Api Attribute

```php
#[Api(
    method: 'GET',
    path: '/api/users',
    tags: ['Users'],
    security: ['bearerAuth'] // Yêu cầu JWT token
)]
public function index() { }
```

**Nhiều security schemes:**
```php
#[Api(
    method: 'GET',
    path: '/api/profile',
    tags: ['Users'],
    security: ['bearerAuth', 'apiKey'] // Có thể dùng JWT hoặc API Key
)]
public function profile() { }
```

### Cách 3: Global Security (Tất cả endpoints đều cần auth)

Cấu hình trong `.env`:
```env
SWAGGER_GLOBAL_SECURITY=bearerAuth
```

Hoặc trong `config/swagger.php`:
```php
'global_security' => ['bearerAuth'],
```

Tất cả endpoints sẽ yêu cầu authentication (trừ khi override trong Api attribute).

### Cấu hình Security Schemes

Trong `config/swagger.php`:
```php
'security_schemes' => [
    'bearerAuth' => [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
        'description' => 'Enter JWT token (Bearer token)',
    ],
    // Có thể thêm API Key
    'apiKey' => [
        'type' => 'apiKey',
        'in' => 'header',
        'name' => 'X-API-Key',
        'description' => 'API Key authentication',
    ],
],
```

### Tắt Auto-detect Security

Nếu không muốn tự động detect từ middleware:
```env
SWAGGER_AUTO_DETECT_SECURITY=false
```

### Ưu tiên Security

1. Api attribute (`security` parameter) - Ưu tiên cao nhất
2. Auto-detect từ middleware - Nếu enabled
3. Global security - Áp dụng cho tất cả nếu không có 2 cái trên

## 4. Form Body Support (form-data, x-www-form-urlencoded)

Package hỗ trợ đầy đủ form body:

### Sử dụng Form Body

```php
#[Api(
    method: 'POST',
    path: '/api/upload',
    tags: ['Upload'],
    contentType: 'multipart/form-data' // hoặc 'application/x-www-form-urlencoded'
)]
public function upload(UploadDto $dto) { }
```

### Content Types được hỗ trợ

- `application/json` (mặc định)
- `multipart/form-data` - Cho file upload
- `application/x-www-form-urlencoded` - Cho form data thông thường

### Ví dụ

```php
// JSON (mặc định)
#[Api(method: 'POST', path: '/api/users', tags: ['Users'])]
public function store(CreateUserDto $dto) { }

// Form Data
#[Api(
    method: 'POST',
    path: '/api/upload',
    tags: ['Upload'],
    contentType: 'multipart/form-data'
)]
public function upload(UploadDto $dto) { }

// URL Encoded
#[Api(
    method: 'POST',
    path: '/api/login',
    tags: ['Auth'],
    contentType: 'application/x-www-form-urlencoded'
)]
public function login(LoginDto $dto) { }
```

## 5. Chỉ định Request Source (Query/Body/Form)

Bạn có thể chỉ định rõ ràng request source trong Api attribute để override auto-detection:

### Cách 1: Dùng requestSource option

```php
#[Api(
    method: 'GET',
    path: '/api/assessment-sets',
    tags: ['Assessment'],
    requestSource: 'query' // Chỉ định rõ: Query Parameters
)]
public function index(AssessmentSetIndexRequest $request) { }

#[Api(
    method: 'POST',
    path: '/api/assessment-sets',
    tags: ['Assessment'],
    requestSource: 'body' // Chỉ định rõ: Request Body (JSON)
)]
public function create(AssessmentSetCreateRequest $request) { }

#[Api(
    method: 'POST',
    path: '/api/upload',
    tags: ['Upload'],
    requestSource: 'form' // Chỉ định rõ: Form Data
)]
public function upload(FileUploadRequest $request) { }
```

### Cách 2: Dùng contentType (tự động detect)

```php
#[Api(
    method: 'POST',
    path: '/api/upload',
    tags: ['Upload'],
    contentType: 'multipart/form-data' // Tự động detect: form
)]
public function upload(FileUploadRequest $request) { }
```

### Cách 3: Auto-detect (mặc định)

```php
// GET + FormRequestDto → Tự động: Query Parameters
#[Api(method: 'GET', path: '/api/assessment-sets', tags: ['Assessment'])]
public function index(AssessmentSetIndexRequest $request) { }

// POST + FormRequestDto → Tự động: Request Body
#[Api(method: 'POST', path: '/api/assessment-sets', tags: ['Assessment'])]
public function create(AssessmentSetCreateRequest $request) { }
```

### Ưu tiên

1. requestSource (nếu có) - Ưu tiên cao nhất
2. contentType (nếu có) - Tự động detect từ contentType
3. Auto-detect (mặc định) - Dựa vào HTTP method và type

### Các giá trị requestSource

- `'query'` → Query Parameters (cho GET request)
- `'body'` → Request Body với `application/json`
- `'form'` → Form Data với `multipart/form-data` (tự động detect file upload)

## 6. File Upload trong Form Data

Swagger tự động detect file fields từ validation rules (`file`, `image`, `mimes`) và generate schema đúng:

```php
class FileUploadRequest extends FormRequestDto
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'image' => ['nullable', 'image', 'mimes:jpg,png', 'max:2048'],
        ];
    }
}

#[Api(
    method: 'POST',
    path: '/api/upload',
    tags: ['Upload'],
    requestSource: 'form' // Tự động detect file fields
)]
public function upload(FileUploadRequest $request) { }
```

Swagger sẽ tự động:
- Detect `file` và `image` là file upload fields
- Set `format: binary` cho file fields
- Thêm `encoding` với content type phù hợp
- Hiển thị file picker trong Swagger UI

## 7. Path Parameters

Path parameters tự động được detect từ method signature:

```php
#[Api(method: 'GET', path: '/api/users/{id}', tags: ['Users'])]
public function show(int $id) { }
```

Hỗ trợ snake_case và camelCase:
```php
#[Api(
    method: 'GET',
    path: '/api/assessment-sets/{id}/{class_assessment_set_id}',
    tags: ['Assessment']
)]
public function showClassAssessmentSet(string $id, string $classAssessmentSetId) { }
```

## 8. Api Attribute Parameters

```php
#[Api(
    method: 'POST|GET|PUT|PATCH|DELETE',  // Required
    path: '/api/path',                     // Required
    tags: ['Tag1', 'Tag2'],                // Required
    
    // Optional parameters
    summary: 'Short description',
    description: 'Detailed description',
    responseType: ResponseDto::class,
    responseCode: 200,                     // Default: 200
    security: ['bearerAuth'],              // Auto-detect from middleware
    contentType: 'application/json',       // Default: 'application/json'
    requestSource: 'query|body|form'    // Auto-detect if not provided
)]
```

## 9. Property Attributes (All Optional)

```php
#[Property(
    description: "Field description",
    example: "example value",
    format: "email|password|date",
    minimum: 0,
    maximum: 100,
    minLength: 3,
    maxLength: 255,
    pattern: "/regex/",
    enum: ["option1", "option2"],
    itemsType: "string"  // For typed arrays: array<string>
)]
```

Bạn có thể bỏ qua tất cả Property attributes và chỉ dùng type hints.

## 10. BaseDto - DTO với Type Hints (Tùy chọn)

Nếu bạn muốn tạo API mới với type-safe DTOs, có thể dùng `BaseDto`:

### Tạo DTO

```php
<?php

namespace App\Dtos;

use Kayne\Swagger\BaseDto;
use Kayne\Swagger\Attributes\Property;

class CreateUserDto extends BaseDto
{
    #[Property(
        description: "Tên người dùng",
        minLength: 3,
        maxLength: 50,
        example: "Nguyễn Văn A"
    )]
    public string $name;
    
    #[Property(
        description: "Email người dùng", 
        format: "email"
    )]
    public string $email;
    
    #[Property(
        description: "Tuổi",
        minimum: 18,
        maximum: 100
    )]
    public ?int $age = null; // Optional field
}
```

### Sử dụng trong Controller

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
        summary: 'Tạo người dùng mới',
        description: 'Endpoint để tạo người dùng',
        tags: ['Users'],
        responseCode: 201
    )]
    public function store(CreateUserDto $dto)
    {
        // DTO đã được validate và bind tự động
        $user = User::create([
            'name' => $dto->name,
            'email' => $dto->email,
            'age' => $dto->age,
        ]);
        
        return response()->json($user, 201);
    }
}
```

### Đăng ký Routes với Middleware

```php
<?php

use Illuminate\Support\Facades\Route;

// Áp dụng middleware 'dto' để tự động validate
Route::middleware(['dto'])->group(function () {
    Route::post('/api/users', [UserController::class, 'store']);
    Route::get('/api/users/{id}', [UserController::class, 'show']);
});
```

### Response DTOs

```php
<?php

namespace App\Dtos;

use Kayne\Swagger\BaseDto;
use Kayne\Swagger\Attributes\Property;

class UserResponseDto extends BaseDto
{
    #[Property(description: "ID người dùng")]
    public int $id;
    
    #[Property(description: "Tên người dùng")]
    public string $name;
    
    #[Property(description: "Email")]
    public string $email;
}
```

Sử dụng trong controller:

```php
#[Api(
    method: 'GET',
    path: '/api/users/{id}',
    summary: 'Lấy thông tin người dùng',
    tags: ['Users'],
    responseType: UserResponseDto::class  // Định nghĩa response schema
)]
public function show(int $id)
{
    $user = User::findOrFail($id);
    $response = UserResponseDto::fromArray($user->toArray());
    
    return response()->json($response->toArray());
}
```

### Nested DTOs

```php
class AddressDto extends BaseDto
{
    public string $street;
    public string $city;
    public string $country;
}

class CreateUserDto extends BaseDto
{
    public string $name;
    public string $email;
    public AddressDto $address; // Nested DTO
}
```

### DTO Methods

```php
// fromRequest()
$dto = CreateUserDto::fromRequest($request);

// fromArray()
$dto = CreateUserDto::fromArray([
    'name' => 'John',
    'email' => 'john@example.com'
]);

// toArray()
$array = $dto->toArray();

// validate()
$errors = $dto->validate();
if (!empty($errors)) {
    // Handle validation errors
}
```

## 11. Configuration

File `config/swagger.php`:

```php
return [
    'title' => env('SWAGGER_TITLE', 'API Documentation'),
    'version' => env('SWAGGER_VERSION', '1.0.0'),
    'description' => env('SWAGGER_DESCRIPTION', ''),
    'route' => env('SWAGGER_ROUTE', 'api/documentation'),
    'enabled' => env('SWAGGER_ENABLED', true),
    
    'auto_detect_security' => env('SWAGGER_AUTO_DETECT_SECURITY', true),
    'global_security' => env('SWAGGER_GLOBAL_SECURITY') ? [env('SWAGGER_GLOBAL_SECURITY')] : null,
    
    'middleware_security_map' => [
        'token' => 'bearerAuth',
        'auth' => 'bearerAuth',
        'auth:sanctum' => 'bearerAuth',
        'jwt' => 'bearerAuth',
    ],
    
    'security_schemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'Enter JWT token (Bearer token)',
        ],
    ],
];
```

## 12. Xem Documentation

Sau khi setup, truy cập:

```
http://your-app.test/api/documentation
```

Swagger UI sẽ hiển thị toàn bộ API documentation được generate tự động từ FormRequestDto/BaseDto và Api attributes.

## So sánh FormRequestDto vs BaseDto

| Feature | BaseDto | FormRequestDto |
|---------|---------|----------------|
| Validation | Từ type hints | Từ `rules()` method |
| Swagger Schema | Từ type hints + Property attributes | Từ `rules()` + Property attributes |
| Use case | API mới, muốn type-safe | Đã có FormRequest, muốn thêm Swagger |
| Nested | Hỗ trợ nested DTOs | Hỗ trợ nested arrays trong rules |

**Khuyến nghị:**
- Dùng `FormRequestDto` khi đã có sẵn FormRequest và muốn tích hợp Swagger (khuyến nghị)
- Dùng `BaseDto` cho API mới, muốn type-safe
