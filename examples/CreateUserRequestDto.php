<?php

namespace App\Dtos;

use Kayne\Swagger\FormRequestDto;
use Kayne\Swagger\Attributes\Property;

/**
 * FormRequestDto - Kết hợp FormRequest validation + Type hints cho Swagger
 */
class CreateUserRequestDto extends FormRequestDto
{
    // Type hints cho Swagger
    #[Property(description: "Tên người dùng")]
    public string $name;

    #[Property(description: "Email người dùng")]
    public string $email;

    public string $password;

    #[Property(description: "Tuổi", minimum: 18)]
    public ?int $age = null;

    #[Property(enum: ["admin", "user", "guest"])]
    public ?string $role = "user";

    /**
     * Laravel validation rules - Swagger sẽ đọc từ đây!
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:50',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'age' => 'nullable|integer|min:18|max:100',
            'role' => 'nullable|in:admin,user,guest',
        ];
    }

    /**
     * Custom validation messages (optional)
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Tên là bắt buộc',
            'email.required' => 'Email là bắt buộc',
            'email.email' => 'Email không đúng định dạng',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự',
        ];
    }

    /**
     * Authorization (optional)
     */
    public function authorize(): bool
    {
        return true; // Hoặc check permission
    }
}
