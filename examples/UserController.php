<?php

namespace App\Http\Controllers;

use App\Dtos\CreateUserDto;
use App\Dtos\UserResponseDto;
use Kayne\Swagger\Attributes\Api;

class UserController extends Controller
{
    // CỰC KỲ ĐƠN GIẢN - Chỉ 3 metadata bắt buộc!
    #[Api(
        method: 'POST',
        path: '/api/users',
        tags: ['Users']
        // Tự động: summary, description, response code 200
        // Tự động detect: CreateUserDto → request body
    )]
    public function store(CreateUserDto $dto)
    {
        // DTO đã được validate tự động qua middleware
        $user = [
            'id' => 1,
            'name' => $dto->name,
            'email' => $dto->email,
            'age' => $dto->age,
            'role' => $dto->role,
            'created_at' => now()->toISOString(),
        ];

        return response()->json($user, 201);
    }

    // Tự động detect path parameter {id}
    #[Api(
        method: 'GET',
        path: '/api/users/{id}',
        tags: ['Users']
        // Tự động: int $id → path parameter
    )]
    public function show(int $id)
    {
        $user = [
            'id' => $id,
            'name' => 'Nguyễn Văn A',
            'email' => 'user@example.com',
            'age' => 25,
            'role' => 'user',
            'created_at' => now()->toISOString(),
        ];

        return response()->json($user);
    }

    // Tự động detect query parameters
    #[Api(
        method: 'GET',
        path: '/api/users',
        tags: ['Users']
        // Tự động: ?page=1&limit=10 → query parameters
    )]
    public function index(?int $page = 1, ?int $limit = 10)
    {
        return response()->json([
            'data' => [
                ['id' => 1, 'name' => 'User 1'],
                ['id' => 2, 'name' => 'User 2'],
            ],
            'page' => $page,
            'limit' => $limit
        ]);
    }

    // Với response type (optional)
    #[Api(
        method: 'PUT',
        path: '/api/users/{id}',
        tags: ['Users'],
        responseType: UserResponseDto::class  // Optional
    )]
    public function update(int $id, CreateUserDto $dto)
    {
        // Tự động: $id → path param, $dto → request body
        $response = UserResponseDto::fromArray([
            'id' => $id,
            'name' => $dto->name,
            'email' => $dto->email,
            'age' => $dto->age,
            'role' => $dto->role,
            'created_at' => now()->toISOString(),
        ]);

        return response()->json($response->toArray());
    }

    #[Api(
        method: 'DELETE',
        path: '/api/users/{id}',
        tags: ['Users']
    )]
    public function destroy(int $id)
    {
        return response()->json(['message' => 'User deleted'], 204);
    }
}
