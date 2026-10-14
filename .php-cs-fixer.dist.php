<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/public'])
    ->append([__FILE__])
    ->notName('HUMANS.md');

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules(['@PER-CS3x0' => true])
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder);
