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
        // Debug: Log rules để kiểm tra
        // error_log('RulesSchemaGenerator::fromRules - Input rules: ' . json_encode($rules, JSON_PRETTY_PRINT));
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => []
        ];

        // Group nested rules (e.g., assessment_sets.*.field) và array items (e.g., ids.*)
        $nestedRules = [];
        $arrayItemRules = []; // Array items như ids.* (không có field sau dấu chấm)
        $topLevelRules = [];

        foreach ($rules as $field => $ruleSet) {
            // Check array items: ids.* (không có field sau dấu chấm)
            if (preg_match('/^(.+)\.\*$/', $field, $matches)) {
                $parentField = $matches[1];
                $arrayItemRules[$parentField] = $ruleSet;
            }
            // Check nested object in array: assessment_sets.*.field
            elseif (strpos($field, '.*.') !== false) {
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
            // Skip if this field has nested rules hoặc array item rules (will be processed separately)
            if (isset($nestedRules[$field]) || isset($arrayItemRules[$field])) {
                continue;
            }

            // Extract description từ rules array nếu có
            $description = null;
            $fieldRules = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            
            // Check cả numeric keys và string keys để tìm description
            if (is_array($ruleSet)) {
                // Check string key 'description'
                if (isset($ruleSet['description'])) {
                    $description = $ruleSet['description'];
                    // Remove description khỏi rules array để không ảnh hưởng validation
                    $fieldRules = $ruleSet;
                    unset($fieldRules['description']);
                    $fieldRules = array_values($fieldRules); // Re-index để loại bỏ gap
                }
                // Check nếu description nằm trong array values (edge case)
                elseif (in_array('description', $ruleSet, true)) {
                    $descIndex = array_search('description', $ruleSet, true);
                    if ($descIndex !== false && isset($ruleSet[$descIndex + 1])) {
                        $description = $ruleSet[$descIndex + 1];
                        // Remove description và value khỏi rules
                        $fieldRules = $ruleSet;
                        unset($fieldRules[$descIndex], $fieldRules[$descIndex + 1]);
                        $fieldRules = array_values($fieldRules);
                    }
                }
            }
            
            $propertySchema = self::parseRules($fieldRules);

            // Thêm description từ rules array (ưu tiên cao hơn Property attributes)
            if ($description !== null) {
                $propertySchema['description'] = $description;
            }

            // Thêm description từ Property attributes nếu có (chỉ khi chưa có từ rules)
            if (isset($properties[$field]) && !isset($propertySchema['description'])) {
                $propertySchema = array_merge($propertySchema, $properties[$field]);
            } elseif (isset($properties[$field])) {
                // Merge các thuộc tính khác (example, format, etc.) nhưng giữ description từ rules
                $otherProps = array_diff_key($properties[$field], ['description' => '']);
                $propertySchema = array_merge($propertySchema, $otherProps);
            }

            $schema['properties'][$field] = $propertySchema;

            // Check required
            if (self::isRequired($fieldRules)) {
                $schema['required'][] = $field;
            }
        }

        // Process array items (ids.*)
        foreach ($arrayItemRules as $parentField => $itemRuleSet) {
            $parentRules = $topLevelRules[$parentField] ?? [];
            $parentFieldRules = is_string($parentRules) ? explode('|', $parentRules) : $parentRules;

            // Extract description từ parent rules nếu có
            $parentDescription = null;
            if (is_array($parentRules) && isset($parentRules['description'])) {
                $parentDescription = $parentRules['description'];
                $parentFieldRules = $parentRules;
                unset($parentFieldRules['description']);
                $parentFieldRules = array_values($parentFieldRules);
            }

            // Parse rules cho array items
            $itemRules = is_string($itemRuleSet) ? explode('|', $itemRuleSet) : $itemRuleSet;
            $itemSchema = self::parseRules($itemRules);

            // Create array schema
            $arraySchema = [
                'type' => 'array',
                'items' => $itemSchema
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

            // Thêm description từ parent rules (ưu tiên cao hơn Property attributes)
            if ($parentDescription !== null) {
                $arraySchema['description'] = $parentDescription;
            }

            // Thêm description từ Property attributes nếu có (chỉ khi chưa có từ rules)
            if (isset($properties[$parentField]) && !isset($arraySchema['description'])) {
                $arraySchema = array_merge($arraySchema, $properties[$parentField]);
            } elseif (isset($properties[$parentField])) {
                // Merge các thuộc tính khác nhưng giữ description từ rules
                $otherProps = array_diff_key($properties[$parentField], ['description' => '']);
                $arraySchema = array_merge($arraySchema, $otherProps);
            }

            $schema['properties'][$parentField] = $arraySchema;

            // Check if parent field is required
            if (self::isRequired($parentFieldRules)) {
                $schema['required'][] = $parentField;
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
                // Extract description từ nested rules nếu có
                $nestedDescription = null;
                $nestedFieldRules = is_string($nestedRuleSet) ? explode('|', $nestedRuleSet) : $nestedRuleSet;
                
                if (is_array($nestedRuleSet) && isset($nestedRuleSet['description'])) {
                    $nestedDescription = $nestedRuleSet['description'];
                    $nestedFieldRules = $nestedRuleSet;
                    unset($nestedFieldRules['description']);
                    $nestedFieldRules = array_values($nestedFieldRules);
                }
                
                $nestedPropertySchema = self::parseRules($nestedFieldRules);

                // Thêm description từ nested rules (ưu tiên cao hơn Property attributes)
                if ($nestedDescription !== null) {
                    $nestedPropertySchema['description'] = $nestedDescription;
                }

                // Thêm description từ Property attributes nếu có (format: parentField.nestedField)
                $nestedPropertyKey = "{$parentField}.{$nestedField}";
                if (isset($properties[$nestedPropertyKey]) && !isset($nestedPropertySchema['description'])) {
                    $nestedPropertySchema = array_merge($nestedPropertySchema, $properties[$nestedPropertyKey]);
                } elseif (isset($properties[$nestedPropertyKey])) {
                    // Merge các thuộc tính khác nhưng giữ description từ rules
                    $otherProps = array_diff_key($properties[$nestedPropertyKey], ['description' => '']);
                    $nestedPropertySchema = array_merge($nestedPropertySchema, $otherProps);
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
                        // Parse enum values, hỗ trợ boolean strings
                        $enumValues = [];
                        foreach ($ruleParams as $param) {
                            // Convert string boolean to actual boolean
                            if (strtolower($param) === 'true') {
                                $enumValues[] = true;
                            } elseif (strtolower($param) === 'false') {
                                $enumValues[] = false;
                            } elseif (is_numeric($param)) {
                                // Giữ nguyên số nếu là số
                                $enumValues[] = strpos($param, '.') !== false ? (float)$param : (int)$param;
                            } else {
                                $enumValues[] = $param;
                            }
                        }
                        $schema['enum'] = $enumValues;
                        // Nếu enum chỉ có true/false, set type là boolean (chỉ khi chưa có type hoặc type là string)
                        if (count($enumValues) === 2 && in_array(true, $enumValues, true) && in_array(false, $enumValues, true)) {
                            // Chỉ set boolean type nếu chưa có type được set (mặc định là string) hoặc type hiện tại là string
                            if (!isset($schema['type']) || $schema['type'] === 'string') {
                                $schema['type'] = 'boolean';
                            }
                        }
                        break;

                    case 'regex':
                        if (isset($ruleParams[0])) {
                            $schema['pattern'] = $ruleParams[0];
                        }
                        break;

                    case 'uuid':
                        $schema['format'] = 'uuid';
                        break;

                    case 'ip':
                        $schema['format'] = 'ipv4';
                        break;

                    case 'ipv4':
                        $schema['format'] = 'ipv4';
                        break;

                    case 'ipv6':
                        $schema['format'] = 'ipv6';
                        break;

                    case 'json':
                        $schema['format'] = 'json';
                        break;

                    case 'file':
                    case 'image':
                        $schema['type'] = 'string';
                        $schema['format'] = 'binary';
                        // Thêm description để detect mime types (chỉ nếu chưa có)
                        if (!isset($schema['description'])) {
                            if ($ruleName === 'image') {
                                $schema['description'] = 'Image file';
                            } else {
                                $schema['description'] = 'File upload';
                            }
                        }
                        break;

                    case 'mimes':
                        // Mimes rule: mimes:jpg,png,pdf
                        if (isset($ruleParams[0]) && !empty($ruleParams[0])) {
                            $mimes = array_filter($ruleParams); // Remove empty values
                            if (!empty($mimes)) {
                                $mimesStr = implode(', ', $mimes);
                                // Append hoặc set description
                                if (isset($schema['description'])) {
                                    $schema['description'] .= ' Allowed types: ' . $mimesStr;
                                } else {
                                    $schema['description'] = 'Allowed types: ' . $mimesStr;
                                }
                                // Nếu chưa có format, set binary
                                if (!isset($schema['format'])) {
                                    $schema['format'] = 'binary';
                                }
                            }
                        }
                        break;

                    case 'timezone':
                        $schema['format'] = 'timezone';
                        break;

                    case 'alpha':
                    case 'alpha_dash':
                    case 'alpha_num':
                        // String type với pattern
                        $schema['type'] = 'string';
                        if ($ruleName === 'alpha') {
                            $schema['pattern'] = '^[a-zA-Z]+$';
                        } elseif ($ruleName === 'alpha_dash') {
                            $schema['pattern'] = '^[a-zA-Z0-9_-]+$';
                        } elseif ($ruleName === 'alpha_num') {
                            $schema['pattern'] = '^[a-zA-Z0-9]+$';
                        }
                        break;

                    case 'digits':
                        if (isset($ruleParams[0])) {
                            $schema['type'] = 'string';
                            $schema['pattern'] = '^\d{' . $ruleParams[0] . '}$';
                        }
                        break;

                    case 'digits_between':
                        if (isset($ruleParams[0]) && isset($ruleParams[1])) {
                            $schema['type'] = 'string';
                            $schema['pattern'] = '^\d{' . $ruleParams[0] . ',' . $ruleParams[1] . '}$';
                        }
                        break;

                    case 'size':
                        if (isset($ruleParams[0])) {
                            if ($schema['type'] === 'string') {
                                $schema['minLength'] = (int)$ruleParams[0];
                                $schema['maxLength'] = (int)$ruleParams[0];
                            } else {
                                $schema['minimum'] = (int)$ruleParams[0];
                                $schema['maximum'] = (int)$ruleParams[0];
                            }
                        }
                        break;

                    case 'gt':
                    case 'greater_than':
                        if (isset($ruleParams[0])) {
                            if ($schema['type'] === 'string') {
                                $schema['minLength'] = (int)$ruleParams[0] + 1;
                            } else {
                                $schema['minimum'] = (int)$ruleParams[0] + 1;
                                $schema['exclusiveMinimum'] = true;
                            }
                        }
                        break;

                    case 'gte':
                    case 'greater_than_or_equal':
                        if (isset($ruleParams[0])) {
                            if ($schema['type'] === 'string') {
                                $schema['minLength'] = (int)$ruleParams[0];
                            } else {
                                $schema['minimum'] = (int)$ruleParams[0];
                            }
                        }
                        break;

                    case 'lt':
                    case 'less_than':
                        if (isset($ruleParams[0])) {
                            if ($schema['type'] === 'string') {
                                $schema['maxLength'] = (int)$ruleParams[0] - 1;
                            } else {
                                $schema['maximum'] = (int)$ruleParams[0] - 1;
                                $schema['exclusiveMaximum'] = true;
                            }
                        }
                        break;

                    case 'lte':
                    case 'less_than_or_equal':
                        if (isset($ruleParams[0])) {
                            if ($schema['type'] === 'string') {
                                $schema['maxLength'] = (int)$ruleParams[0];
                            } else {
                                $schema['maximum'] = (int)$ruleParams[0];
                            }
                        }
                        break;

                    case 'not_in':
                        // Enum với notIn - không hỗ trợ trực tiếp trong OpenAPI, dùng pattern
                        if (!empty($ruleParams)) {
                            $schema['not'] = ['enum' => $ruleParams];
                        }
                        break;

                    case 'starts_with':
                        if (!empty($ruleParams)) {
                            $schema['type'] = 'string';
                            $pattern = '^(' . implode('|', array_map('preg_quote', $ruleParams)) . ')';
                            $schema['pattern'] = $pattern;
                        }
                        break;

                    case 'ends_with':
                        if (!empty($ruleParams)) {
                            $schema['type'] = 'string';
                            $pattern = '(' . implode('|', array_map('preg_quote', $ruleParams)) . ')$';
                            $schema['pattern'] = $pattern;
                        }
                        break;

                    case 'string':
                        $schema['type'] = 'string';
                        break;

                    case 'nullable':
                        // Nullable được xử lý ở isRequired
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
