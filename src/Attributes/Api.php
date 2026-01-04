<?php

namespace Kayne\Swagger\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Api
{
    public function __construct(
        public string $method,
        public string $path,
        public array $tags,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $responseType = null,
        public ?int $responseCode = null,
        public ?array $security = null, // Security schemes: ['bearerAuth'] hoặc ['bearerAuth', 'apiKey']
        public ?string $contentType = null, // Content type: 'application/json', 'multipart/form-data', 'application/x-www-form-urlencoded'
    ) {}
}
