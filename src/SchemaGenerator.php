<?php

namespace Kayne\Swagger;

use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;
use Kayne\Swagger\Attributes\Property;

class SchemaGenerator
{
    /**
     * Tạo schema từ DTO class hoặc FormRequestDto
     */
    public static function generate(string $dtoClass): array
    {
        // Kiểm tra xem có phải FormRequestDto không
        if (is_subclass_of($dtoClass, FormRequestDto::class)) {
            return self::generateFromFormRequest($dtoClass);
        }

        if (!is_subclass_of($dtoClass, BaseDto::class)) {
            throw new \InvalidArgumentException("Class phải kế thừa BaseDto hoặc FormRequestDto");
        }

        return self::generateFromDto($dtoClass);
    }

    /**
     * Generate từ FormRequestDto (có rules)
     */
    private static function generateFromFormRequest(string $dtoClass): array
    {
        $instance = new $dtoClass();
        $rules = method_exists($instance, 'rules') ? $instance->rules() : [];

        // Lấy metadata từ Property attributes
        $reflection = new ReflectionClass($dtoClass);
        $propertyMetadata = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $attributes = $property->getAttributes(Property::class);

            if (!empty($attributes)) {
                $attr = $attributes[0]->newInstance();
                $meta = [];

                if ($attr->description) $meta['description'] = $attr->description;
                if ($attr->example !== null) $meta['example'] = $attr->example;
                if ($attr->format) $meta['format'] = $attr->format;

                $propertyMetadata[$name] = $meta;
            }
        }

        // Generate schema từ rules + metadata
        return RulesSchemaGenerator::fromRules($rules, $propertyMetadata);
    }

    /**
     * Generate từ BaseDto (type hints)
     */
    private static function generateFromDto(string $dtoClass): array
    {
        $reflection = new ReflectionClass($dtoClass);
        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $type = $property->getType();

            // Xác định type
            $schema = self::getPropertySchema($property, $type);

            // Thêm description và constraints từ Property attribute
            $attributes = $property->getAttributes(Property::class);
            if (!empty($attributes)) {
                $attr = $attributes[0]->newInstance();

                if ($attr->description) {
                    $schema['description'] = $attr->description;
                }
                if ($attr->example !== null) {
                    $schema['example'] = $attr->example;
                }
                if ($attr->format) {
                    $schema['format'] = $attr->format;
                }
                if ($attr->minimum !== null) {
                    $schema['minimum'] = $attr->minimum;
                }
                if ($attr->maximum !== null) {
                    $schema['maximum'] = $attr->maximum;
                }
                if ($attr->minLength !== null) {
                    $schema['minLength'] = $attr->minLength;
                }
                if ($attr->maxLength !== null) {
                    $schema['maxLength'] = $attr->maxLength;
                }
                if ($attr->pattern) {
                    $schema['pattern'] = $attr->pattern;
                }
                if ($attr->enum !== null) {
                    $schema['enum'] = $attr->enum;
                }
            }

            $properties[$name] = $schema;

            // Xác định required
            if ($type && !$type->allowsNull()) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Lấy schema cho property
     */
    private static function getPropertySchema(ReflectionProperty $property, $type): array
    {
        if (!$type) {
            return ['type' => 'string'];
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            // Kiểm tra nested DTO
            if (class_exists($typeName) && (is_subclass_of($typeName, BaseDto::class) || is_subclass_of($typeName, FormRequestDto::class))) {
                return self::generate($typeName);
            }

            $schema = self::mapPhpTypeToOpenApi($typeName);
            
            // Nếu là array và có Property attribute với itemsType
            if ($schema['type'] === 'array') {
                $attributes = $property->getAttributes(Property::class);
                if (!empty($attributes)) {
                    $attr = $attributes[0]->newInstance();
                    if ($attr->itemsType) {
                        $schema['items'] = self::mapPhpTypeToOpenApi($attr->itemsType);
                    }
                }
            }
            
            return $schema;
        }

        if ($type instanceof ReflectionUnionType) {
            $types = [];
            foreach ($type->getTypes() as $unionType) {
                if ($unionType->getName() !== 'null') {
                    $types[] = self::mapPhpTypeToOpenApi($unionType->getName());
                }
            }

            if (count($types) === 1) {
                return $types[0];
            }

            return ['oneOf' => $types];
        }

        return ['type' => 'string'];
    }

    /**
     * Map PHP type sang OpenAPI type
     */
    private static function mapPhpTypeToOpenApi(string $phpType): array
    {
        return match($phpType) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number', 'format' => 'float'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'string' => ['type' => 'string'],
            'object' => ['type' => 'object'],
            default => ['type' => 'string'],
        };
    }
}
