<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PHP54Migration' => true,
        '@PSR2' => true,
        '@PSR12' => true,
        '@PHP70Migration' => true,
        '@PHP71Migration' => true,
        '@PHP74Migration' => true,
        '@Symfony' => true
    ])
    ->setFinder($finder);