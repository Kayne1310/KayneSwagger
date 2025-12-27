<?php

namespace Kayne\Swagger;

use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use Kayne\Swagger\Attributes\Property;

abstract class BaseDto
{
    /**
     * Tạo DTO từ Request
     */
    public static function fromRequest(Request $request): static
    {
        $dto = new static();
        $reflection = new ReflectionClass($dto);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if ($request->has($name)) {
                $value = $request->input($name);
                $type = $property->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    // Xử lý nested DTO
                    $className = $type->getName();
                    if (is_subclass_of($className, BaseDto::class)) {
                        $value = $className::fromArray($value);
                    }
                }

                $property->setValue($dto, $value);
            }
        }

        return $dto;
    }

    /**
     * Tạo DTO từ array
     */
    public static function fromArray(array $data): static
    {
        $dto = new static();
        $reflection = new ReflectionClass($dto);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (array_key_exists($name, $data)) {
                $value = $data[$name];
                $type = $property->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $className = $type->getName();
                    if (is_subclass_of($className, BaseDto::class) && is_array($value)) {
                        $value = $className::fromArray($value);
                    }
                }

                $property->setValue($dto, $value);
            }
        }

        return $dto;
    }

    /**
     * Chuyển DTO thành array
     */
    public function toArray(): array
    {
        $result = [];
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $value = $property->getValue($this);

            if ($value instanceof BaseDto) {
                $value = $value->toArray();
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Validate DTO
     */
    public function validate(): array
    {
        $errors = [];
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $type = $property->getType();
            $value = $property->isInitialized($this) ? $property->getValue($this) : null;

            // Kiểm tra required
            if ($type && !$type->allowsNull() && $value === null) {
                $errors[$name][] = "Trường {$name} là bắt buộc";
                continue;
            }

            if ($value === null) {
                continue;
            }

            // Kiểm tra type
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();

                if ($typeName === 'int' && !is_int($value)) {
                    $errors[$name][] = "Trường {$name} phải là số nguyên";
                } elseif ($typeName === 'string' && !is_string($value)) {
                    $errors[$name][] = "Trường {$name} phải là chuỗi";
                } elseif ($typeName === 'bool' && !is_bool($value)) {
                    $errors[$name][] = "Trường {$name} phải là boolean";
                } elseif ($typeName === 'float' && !is_float($value) && !is_int($value)) {
                    $errors[$name][] = "Trường {$name} phải là số thực";
                } elseif ($typeName === 'array' && !is_array($value)) {
                    $errors[$name][] = "Trường {$name} phải là mảng";
                }
            }

            // Kiểm tra Property attributes
            $attributes = $property->getAttributes(Property::class);
            if (!empty($attributes)) {
                $attr = $attributes[0]->newInstance();

                if (is_string($value)) {
                    if ($attr->minLength !== null && strlen($value) < $attr->minLength) {
                        $errors[$name][] = "Trường {$name} phải có ít nhất {$attr->minLength} ký tự";
                    }
                    if ($attr->maxLength !== null && strlen($value) > $attr->maxLength) {
                        $errors[$name][] = "Trường {$name} không được vượt quá {$attr->maxLength} ký tự";
                    }
                    if ($attr->pattern !== null && !preg_match($attr->pattern, $value)) {
                        $errors[$name][] = "Trường {$name} không đúng định dạng";
                    }
                }

                if (is_numeric($value)) {
                    if ($attr->minimum !== null && $value < $attr->minimum) {
                        $errors[$name][] = "Trường {$name} phải lớn hơn hoặc bằng {$attr->minimum}";
                    }
                    if ($attr->maximum !== null && $value > $attr->maximum) {
                        $errors[$name][] = "Trường {$name} phải nhỏ hơn hoặc bằng {$attr->maximum}";
                    }
                }

                if ($attr->enum !== null && !in_array($value, $attr->enum, true)) {
                    $errors[$name][] = "Trường {$name} phải là một trong: " . implode(', ', $attr->enum);
                }
            }
        }

        return $errors;
    }
}
