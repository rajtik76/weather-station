<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
        __DIR__.'/resources',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        // Config and route files are plain returns/closures; strict_types adds only noise.
        SafeDeclareStrictTypesRector::class => [
            __DIR__.'/bootstrap',
            __DIR__.'/config',
            __DIR__.'/routes',
        ],
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    ->withComposerBased(laravel: true)
    ->withImportNames();
