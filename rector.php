<?php

use Pest\Rector\Set\PestSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use RectorLaravel\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector;
use RectorLaravel\Set\LaravelSetList;

/*
 * config/ and bootstrap/app.php are deliberately absent from the paths: they are
 * returned-array and fluent-builder files where the prepared sets have nothing useful
 * to say, and bootstrap/app.php is loaded below as a bootstrap file, so having Rector
 * rewrite the file it also boots from is a knot worth not tying.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        PestSetList::CODING_STYLE,
    ])
    ->withRules([
        AddGenericReturnTypeToRelationsRector::class,
        DeclareStrictTypesRector::class,
    ])
    /*
     * Turning `fn () => helper()` into `helper(...)` is wrong inside a Pest dataset. Pest
     * rebinds a dataset's closures to the test case, and a closure made from a plain function
     * cannot be rebound - PHP 8.5 warns and PHP 9 will make it an error, which arrives as a
     * failed test rather than as anything that reads like a scope problem.
     *
     * Scoped to tests because the rewrite is a real improvement anywhere the closure is not
     * about to be rebound, which is everywhere else.
     */
    ->withSkip([
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__.'/tests',
        ],
    ])
    /*
     * Doc block names are left alone: importing them churns every annotation in the
     * diff for no runtime effect, and PHPStan resolves them fine either way.
     */
    ->withImportNames(importDocBlockNames: false)
    /*
     * Rector needs the container to resolve Laravel types (relations, facades). This
     * is the same file the framework boots from, so there is nothing to keep in sync.
     */
    ->withBootstrapFiles([
        __DIR__.'/bootstrap/app.php',
    ])
    ->withCache(
        cacheDirectory: '/tmp/rector/cache',
        cacheClass: FileCacheStorage::class,
    );
