<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PHP5x4Migration' => true,
        '@PSR2' => true,
        '@PSR12' => true,
        '@PHP7x0Migration' => true,
        '@PHP7x1Migration' => true,
        '@PHP7x4Migration' => true,
        '@Symfony' => true,
    ])
    ->setFinder($finder);
