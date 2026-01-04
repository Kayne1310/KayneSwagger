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

        // Group nested rules (e.g., assessment_sets.*.field)
        $nestedRules = [];
        $topLevelRules = [];

        foreach ($rules as $field => $ruleSet) {
            if (strpos($field, '.*.') !== false) {
                // Nested array rule
                $parts = explode('.*.', $field);
                $parentField = $parts[0];
                $nestedField = $parts[1] ?? null;

                if (!isset($nestedRules[$parentField])) {
                    $nestedRules[$parentField] = [];
                }

                if ($nestedField) {
                    $nestedRules[$parentField][$nestedField] = $ruleSet;
                }
            } else {
                $topLevelRules[$field] = $ruleSet;
            }
        }

        // Process top-level rules
        foreach ($topLevelRules as $field => $ruleSet) {
            // Skip if this field has nested rules (will be processed separately)
            if (isset($nestedRules[$field])) {
                continue;
            }

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

        // Process nested rules
        foreach ($nestedRules as $parentField => $nestedFields) {
            // Check if parent field is array in top-level rules
            $parentRules = $topLevelRules[$parentField] ?? [];
            $parentFieldRules = is_string($parentRules) ? explode('|', $parentRules) : $parentRules;

            // Generate schema for nested object
            $nestedSchema = [
                'type' => 'object',
                'properties' => [],
                'required' => []
            ];

            foreach ($nestedFields as $nestedField => $nestedRuleSet) {
                $nestedFieldRules = is_string($nestedRuleSet) ? explode('|', $nestedRuleSet) : $nestedRuleSet;
                $nestedPropertySchema = self::parseRules($nestedFieldRules);

                // Thêm description từ Property attributes nếu có (format: parentField.nestedField)
                $nestedPropertyKey = "{$parentField}.{$nestedField}";
                if (isset($properties[$nestedPropertyKey])) {
                    $nestedPropertySchema = array_merge($nestedPropertySchema, $properties[$nestedPropertyKey]);
                }

                $nestedSchema['properties'][$nestedField] = $nestedPropertySchema;

                // Check required
                if (self::isRequired($nestedFieldRules)) {
                    $nestedSchema['required'][] = $nestedField;
                }
            }

            if (empty($nestedSchema['required'])) {
                unset($nestedSchema['required']);
            }

            // Create array schema with nested object items
            $arraySchema = [
                'type' => 'array',
                'items' => $nestedSchema
            ];

            // Add min/max items if specified in parent rules
            foreach ($parentFieldRules as $rule) {
                if (is_string($rule)) {
                    $ruleParts = explode(':', $rule);
                    $ruleName = $ruleParts[0];
                    $ruleParams = isset($ruleParts[1]) ? explode(',', $ruleParts[1]) : [];

                    if ($ruleName === 'min' && isset($ruleParams[0])) {
                        $arraySchema['minItems'] = (int)$ruleParams[0];
                    } elseif ($ruleName === 'max' && isset($ruleParams[0])) {
                        $arraySchema['maxItems'] = (int)$ruleParams[0];
                    }
                }
            }

            $schema['properties'][$parentField] = $arraySchema;

            // Check if parent field is required
            if (self::isRequired($parentFieldRules)) {
                $schema['required'][] = $parentField;
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
