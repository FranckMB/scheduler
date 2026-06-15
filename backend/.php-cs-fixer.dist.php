<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude("var")
    ->notPath([
        "config/bundles.php",
        "config/reference.php",
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP84Migration' => true,
        '@PHP80Migration:risky' => true,

        // Yoda style (Enoptea convention)
        'yoda_style' => [
            'equal' => true,
            'identical' => true,
            'less_and_greater' => false,
        ],

        // Strict comparisons (equivalent to SlevomatCodingStandard strict rules)
        'strict_comparison' => true,
        'strict_param' => true,

        // declare(strict_types=1) on same line as opening tag
        'declare_strict_types' => true,
        'blank_line_after_opening_tag' => false,

        // Trailing comma in multiline (cleaner diffs)
        // Equivalent to SlevomatCodingStandard.Functions.RequireTrailingCommaInCall/Declaration
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],

        // Do not force static closures
        // Equivalent to SlevomatCodingStandard.Functions.StaticClosure severity 0
        'static_lambda' => false,

        // Allow fully qualified global functions/constants
        // Equivalent to SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // New without parentheses when no arguments
        // Equivalent to SlevomatCodingStandard.ControlStructures.NewWithoutParentheses
        'new_with_parentheses' => [
            'anonymous_class' => false,
            'named_class' => false,
        ],

        // Nullable type for null default value
        // Equivalent to SlevomatCodingStandard.TypeHints.NullableTypeForNullDefaultValue
        'nullable_type_declaration_for_default_null_value' => true,

        // Useless code removal
        // Equivalent to SlevomatCodingStandard.PHP.UselessParentheses / UselessSemicolon
        'no_useless_else' => true,
        'no_useless_return' => true,
        'no_empty_statement' => true,

        // Modern class name reference: use static::class instead of hardcoded strings
        // Equivalent to SlevomatCodingStandard.Classes.ModernClassNameReference
        'self_static_accessor' => true,

        // Language construct spacing
        // Equivalent to Generic.WhiteSpace.LanguageConstructSpacing
        'single_space_around_construct' => true,

        // PHPDoc annotations ordering and spacing
        // Equivalent to SlevomatCodingStandard.Commenting.DocCommentSpacing
        'phpdoc_order' => true,
        'phpdoc_separation' => true,

        // Do not convert phpdoc to return type (tests readability)
        'phpdoc_to_return_type' => false,

        // Allow double-quoted strings with variables inside
        // Equivalent to Squiz.Strings.DoubleQuoteUsage.ContainsVar severity 0
        'single_quote' => ['strings_containing_single_quote_chars' => true],

        // Single-line empty body for constructors etc.
        'single_line_empty_body' => true,

        // Concat spacing with spaces
        'concat_space' => ['spacing' => 'one'],

        // Ordered imports alphabetically
        'ordered_imports' => ['sort_algorithm' => 'alpha'],

        // Nullable type declaration: null at the end (equivalent to DNFTypeHintFormat nullPosition=last)
        'nullable_type_declaration' => ['syntax' => 'question_mark'],

        // Fully qualified strict types
        'fully_qualified_strict_types' => true,

        // ########################
        // Best practices rules
        // ########################

        // Performance: prefix native functions with backslash to skip namespace resolution
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],

        // Performance: prefix native constants with backslash
        'native_constant_invocation' => [
            'scope' => 'namespaced',
            'strict' => true,
        ],

        // Remove unused use statements
        'no_unused_imports' => true,

        // Order class elements: constants, properties, constructor, methods
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],

        // Remove superfluous PHPDoc tags when native types exist
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => true,
        ],

        // Add void return type to methods that return nothing
        'void_return' => true,

        // Use modern type casting: (int) instead of intval()
        'modernize_types_casting' => true,

        // Use [] instead of array()
        'array_syntax' => ['syntax' => 'short'],

        // Use [$a, $b] instead of list($a, $b)
        'list_syntax' => ['syntax' => 'short'],

        // Remove useless sprintf with single %s
        'no_useless_sprintf' => true,

        // Use alias functions: count() not sizeof()
        'no_alias_functions' => true,

        // Simplify if/return boolean patterns
        'simplified_if_return' => true,

        // Blank line between methods
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'property' => 'one',
            ],
        ],

        // Consistent method argument spacing
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
        ],
    ])
    ->setFinder($finder)
    ;
