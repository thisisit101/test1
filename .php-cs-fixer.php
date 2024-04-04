<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/plugins'])
    ->ignoreDotFiles(false)
    ->name('*.inc')
    ->name('*.php.dist');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP74Migration' => true,
        '@PHP74Migration:risky' => true,
        '@PHP80Migration' => true,
        '@PSR1' => true,
        '@PSR12' => true,

        'concat_space' => [
            'spacing' => 'one',
        ],

        'declare_strict_types' => false,
        'phpdoc_add_missing_param_annotation' => false,
        'use_arrow_functions' => false,
        'void_return' => false,

        'yoda_style' => [
            'equal' => false,
            'identical' => false,
        ],

        // TODO - risky
        'no_unset_on_property' => false,
        'random_api_migration' => false,
        'strict_param' => false,

        // php 7.2 compat
        'assign_null_coalescing_to_coalesce_equal' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(sys_get_temp_dir() . '/php-cs-fixer.' . md5(__DIR__) . '.cache');
