<?php declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__);

return new PhpCsFixer\Config()
    ->setFinder($finder)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setCacheFile('.cache/cs-fixer.cache')
    ->setRules([
        'linebreak_after_opening_tag' => false,
        'blank_line_after_opening_tag' => false,
        'blank_line_before_statement' => true,
        'phpdoc_summary' => false,
        'phpdoc_annotation_without_dot' => false,
        'phpdoc_to_comment' => false,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_useless_else' => true,
        'void_return' => true,
        'phpdoc_line_span' => true,
        'php_unit_dedicate_assert_internal_type' => true,
        'php_unit_mock' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'static'],
        'no_useless_return' => true,
        'new_expression_parentheses' => ['use_parentheses' => false],
        'ordered_class_elements' => true,
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
            'less_and_greater' => false,
        ],
        'single_line_throw' => false,
        'fopen_flags' => false,
        'self_accessor' => false,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => ['order' => ['param', 'throws', 'return']],
        'class_attributes_separation' => ['elements' => ['property' => 'one', 'method' => 'one']],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'concat_space' => ['spacing' => 'one'],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'general_phpdoc_annotation_remove' => ['annotations' => ['copyright', 'category']],
        'no_superfluous_phpdoc_tags' => ['allow_unused_params' => true, 'allow_mixed' => true],
        'php_unit_dedicate_assert' => ['target' => 'newest'],
        'single_quote' => ['strings_containing_single_quote_chars' => true],
        'fully_qualified_strict_types' => [
            'import_symbols' => true,
            'phpdoc_tags' => ['param', 'phpstan-param', 'phpstan-property', 'phpstan-property-read', 'phpstan-property-write', 'phpstan-return', 'phpstan-var', 'property', 'property-read', 'property-write', 'psalm-param', 'psalm-property', 'psalm-property-read', 'psalm-property-write', 'psalm-return', 'psalm-var', 'return', 'throws'],
        ],
    ]);
