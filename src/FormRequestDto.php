<?php

namespace Kayne\Swagger;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use Kayne\Swagger\Attributes\Property;

/**
 * DTO kết hợp FormRequest - Có cả validation rules và type safety
 */
abstract class FormRequestDto extends FormRequest
{
    /**
     * Tự động populate properties từ validated data sau khi validate
     */
    protected function passedValidation(): void
    {
        $validated = $this->validated();
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (array_key_exists($name, $validated)) {
                $value = $validated[$name];
                $type = $property->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $className = $type->getName();
                    if (is_subclass_of($className, FormRequestDto::class) && is_array($value)) {
                        // Nested DTO
                        $nestedDto = new $className();
                        $nestedDto->merge($value);
                        $value = $nestedDto;
                    }
                }

                $property->setValue($this, $value);
            }
        }
    }

    /**
     * Chuyển thành array
     */
    public function toArray(): array
    {
        $result = [];
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $name = $property->getName();
                $value = $property->getValue($this);

                if ($value instanceof FormRequestDto) {
                    $value = $value->toArray();
                }

                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Lấy rules để generate schema
     */
    abstract public function rules(): array;

    /**
     * Authorization - mặc định true, override nếu cần
     */
    public function authorize(): bool
    {
        return true;
    }
}
