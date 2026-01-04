<?php

namespace Kayne\Swagger\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Property
{
    public function __construct(
        public ?string $description = null,
        public mixed $example = null, // Hỗ trợ string, number, boolean, array, object
        public ?string $format = null,
        public ?int $minimum = null,
        public ?int $maximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?array $enum = null,
        public ?string $itemsType = null, // Type cho array items: 'string', 'integer', 'object', etc.
    ) {}

    /**
     * Kiểm tra xem có metadata nào được set không
     */
    public function isEmpty(): bool
    {
        return $this->description === null
            && $this->example === null
            && $this->format === null
            && $this->minimum === null
            && $this->maximum === null
            && $this->minLength === null
            && $this->maxLength === null
            && $this->pattern === null
            && $this->enum === null;
    }
}
