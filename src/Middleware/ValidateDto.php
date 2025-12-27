<?php

namespace Kayne\Swagger\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kayne\Swagger\BaseDto;
use Kayne\Swagger\FormRequestDto;
use Illuminate\Validation\ValidationException;

class ValidateDto
{
    /**
     * Handle an incoming request và validate DTO
     */
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();

        if (!$route) {
            return $next($request);
        }

        $parameters = $route->signatureParameters();

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (!$type || !($type instanceof \ReflectionNamedType)) {
                continue;
            }

            $typeName = $type->getName();

            // Hỗ trợ cả BaseDto và FormRequestDto
            if (!class_exists($typeName)) {
                continue;
            }

            // Nếu là FormRequestDto, Laravel tự validate
            if (is_subclass_of($typeName, FormRequestDto::class)) {
                // FormRequest sẽ tự validate, không cần xử lý ở đây
                continue;
            }

            // Nếu là BaseDto
            if (is_subclass_of($typeName, BaseDto::class)) {
                // Tạo DTO từ request
                $dto = $typeName::fromRequest($request);

                // Validate
                $errors = $dto->validate();

                if (!empty($errors)) {
                    throw ValidationException::withMessages($errors);
                }

                // Bind DTO vào request
                $request->attributes->set($parameter->getName(), $dto);
            }
        }

        return $next($request);
    }
}
