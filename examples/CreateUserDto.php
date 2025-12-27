<?php

namespace App\Dtos;

use Kayne\Swagger\BaseDto;
use Kayne\Swagger\Attributes\Property;

class CreateUserDto extends BaseDto
{
    // CỰC KỲ ĐỠN GIẢN - Có thể không cần Property attribute!
    // Chỉ cần type hint, tự động generate swagger
    public string $name;
    public string $email;
    public string $password;

    // Hoặc có thể thêm metadata nếu muốn
    #[Property(description: "Tuổi người dùng", minimum: 18, maximum: 100)]
    public ?int $age = null;

    #[Property(enum: ["admin", "user", "guest"])]
    public ?string $role = "user";
}
