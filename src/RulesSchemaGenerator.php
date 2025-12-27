<?php

namespace Kayne\Swagger;

use Kayne\Swagger\Attributes\Property;

class RulesSchemaGenerator
{
    /**
     * Generate schema từ Laravel validation rules
     */
    public static function fromRules(array $rules, array $properties = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ];

        foreach ($rules as $field => $ruleSet) {
            $fieldRules = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            $propertySchema = self::parseRules($fieldRules);

            // Thêm description từ Property attributes nếu có
            if (isset($properties[$field])) {
                $propertySchema = array_merge($propertySchema, $properties[$field]);
            }

            $schema['properties'][$field] = $propertySchema;

            // Check required
            if (self::isRequired($fieldRules)) {
                $schema['required'][] = $field;
            }
        }

        if (empty($schema['required'])) {
            unset($schema['required']);
        }

        return $schema;
    }

    /**
     * Parse Laravel validation rules thành OpenAPI schema
     */
    private static function parseRules(array $rules): array
    {
        $schema = ['type' => 'string'];

        foreach ($rules as $rule) {
            if (is_object($rule)) {
                $rule = get_class($rule);
            }

            if (is_string($rule)) {
                $ruleParts = explode(':', $rule);
                $ruleName = $ruleParts[0];
                $ruleParams = isset($ruleParts[1]) ? explode(',', $ruleParts[1]) : [];

                switch ($ruleName) {
                    case 'integer':
                    case 'int':
                        $schema['type'] = 'integer';
                        break;

                    case 'numeric':
                    case 'number':
                        $schema['type'] = 'number';
                        break;

                    case 'boolean':
                    case 'bool':
                        $schema['type'] = 'boolean';
                        break;

                    case 'array':
                        $schema['type'] = 'array';
                        $schema['items'] = ['type' => 'string'];
                        break;

                    case 'email':
                        $schema['format'] = 'email';
                        break;

                    case 'url':
                        $schema['format'] = 'uri';
                        break;

                    case 'date':
                        $schema['format'] = 'date';
                        break;

                    case 'date_format':
                        $schema['format'] = 'date-time';
                        break;

                    case 'min':
                        if (isset($ruleParams[0])) {
                            if ($schema['type'] === 'string') {
                                $schema['minLength'] = (int)$ruleParams[0];
                            } else {
                                $schema['minimum'] = (int)$ruleParams[0];
                            }
                        }
                        break;

                    case 'max':
                        if (isset($ruleParams[0])) {
                            if ($schema['type'] === 'string') {
                                $schema['maxLength'] = (int)$ruleParams[0];
                            } else {
                                $schema['maximum'] = (int)$ruleParams[0];
                            }
                        }
                        break;

                    case 'between':
                        if (isset($ruleParams[0]) && isset($ruleParams[1])) {
                            if ($schema['type'] === 'string') {
                                $schema['minLength'] = (int)$ruleParams[0];
                                $schema['maxLength'] = (int)$ruleParams[1];
                            } else {
                                $schema['minimum'] = (int)$ruleParams[0];
                                $schema['maximum'] = (int)$ruleParams[1];
                            }
                        }
                        break;

                    case 'in':
                        $schema['enum'] = $ruleParams;
                        break;

                    case 'regex':
                        if (isset($ruleParams[0])) {
                            $schema['pattern'] = $ruleParams[0];
                        }
                        break;
                }
            }
        }

        return $schema;
    }

    /**
     * Kiểm tra xem field có required không
     */
    private static function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule === 'required' || (is_string($rule) && strpos($rule, 'required') === 0)) {
                return true;
            }
        }
        return false;
    }
}
