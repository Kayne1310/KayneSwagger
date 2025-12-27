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

    #[Property(description: "Tuổi")]
    public ?int $age;

    #[Property(description: "Vai trò")]
    public string $role;

    #[Property(description: "Thời gian tạo")]
    public string $created_at;
}
