<?php

require_once(__DIR__ . '/../vendor/autoload.php');

$finder = \PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/../src');

return (new PhpCsFixer\Config())
    ->setRules([
        'no_unused_imports' => true,
        'braces' => [
            'position_after_functions_and_oop_constructs' => 'same',
            'allow_single_line_closure' => true
        ],
    ])
    ->setFinder($finder);
