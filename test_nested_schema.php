<?php

// Direct include without autoloader
require_once __DIR__ . '/src/RulesSchemaGenerator.php';

use Kayne\Swagger\RulesSchemaGenerator;

// Test case: Mixed validation rules (nested objects + arrays)
$rules = [
    'setup.assessmentName'     => ['required', 'string'],
    'setup.subjectId'          => ['required', 'string'],
    'setup.gradeLevelId'       => ['required', 'string'],
    'setup.gradeScaleId'       => ['required', 'string'],
    'setup.numberOfQuestion'   => ['required', 'integer'],
    'setup.assessmentSetTime'  => ['required', 'integer'],
    'setup.difficulty'         => ['required', 'integer'],

    'questions' => ['required', 'array'],
    'questions.*.assessment_item_id' => ['required', 'string'],
    'questions.*.position' => ['required', 'integer'],

    'meta.isDraft' => ['required', 'boolean'],
];

$schema = RulesSchemaGenerator::fromRules($rules);

// Save to JSON file
file_put_contents(__DIR__ . '/test_output.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Schema generated successfully!\n";
echo "📄 Output saved to: test_output.json\n\n";

// Show summary
echo "Summary:\n";
echo "- setup: " . ($schema['properties']['setup']['type'] ?? 'unknown') . "\n";
echo "- questions: " . ($schema['properties']['questions']['type'] ?? 'unknown') . "\n";
echo "- meta: " . ($schema['properties']['meta']['type'] ?? 'unknown') . "\n";

// Validate structure
$errors = [];

if (($schema['properties']['setup']['type'] ?? null) !== 'object') {
    $errors[] = "setup should be object";
}
if (($schema['properties']['questions']['type'] ?? null) !== 'array') {
    $errors[] = "questions should be array";
}
if (($schema['properties']['meta']['type'] ?? null) !== 'object') {
    $errors[] = "meta should be object";
}

if (empty($errors)) {
    echo "\n🎉 All validations passed!\n";
} else {
    echo "\n❌ Validation errors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    exit(1);
}
