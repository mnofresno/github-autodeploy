<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('vendor')
    ->in(__DIR__);

return (new PhpCsFixer\Config())
    ->setRules([
        'array_syntax'                        => ['syntax' => 'short'],
        'function_declaration'                => true,
        'full_opening_tag'                    => true,
        'indentation_type'                    => true,
        'new_with_parentheses'                => true,
        'no_closing_tag'                      => true,
        'no_trailing_whitespace'              => true,
        'single_blank_line_at_eof'            => true,
        'ternary_operator_spaces'             => true,
        'blank_line_after_opening_tag'        => true,
        'ternary_to_null_coalescing'          => true,
        'no_unneeded_control_parentheses'     => true,
        'no_unneeded_braces'                  => true,
        'no_null_property_initialization'     => true,
        'no_extra_blank_lines'                => true,
        'no_spaces_after_function_name'       => true,
        'cast_spaces'                         => ['space' => 'single'],
        'encoding'                            => true,
        'no_whitespace_before_comma_in_array' => true,
        'object_operator_without_whitespace'  => true,
        'unary_operator_spaces'               => true,
        'binary_operator_spaces'              => [
            'default' => 'single_space',
            // We would like to also apply the "single_space" rule to both "=" and "=>"
            // operators but developers use them too much when aligning vertically
            // assignments of variables or when defining assosiative arrays
            'operators' => [
                '='  => null,
                '=>' => null,
            ],
        ],
        'concat_space'                    => ['spacing' => 'one'],
        'no_empty_statement'              => true,
        'return_type_declaration'         => ['space_before' => 'none'],
        'whitespace_after_comma_in_array' => true,
        'method_argument_space'           => true,
        'type_declaration_spaces'         => true,
        'no_unused_imports'               => true,
        'no_leading_import_slash'         => true,
        '@PSR12'                          => true,
        'braces_position'                 => [
            'classes_opening_brace'            => 'same_line',
            'control_structures_opening_brace' => 'same_line',
            'functions_opening_brace'          => 'same_line',
        ],
        'statement_indentation'           => true,
        'trailing_comma_in_multiline'     => true,
        'trim_array_spaces'               => true,
        'class_attributes_separation'     => [
            'elements' => [
                'method' => 'one',
            ],
        ],
    ])
    ->setFinder($finder);
