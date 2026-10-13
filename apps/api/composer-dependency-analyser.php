<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToScan(__DIR__.'/config', false)
    ->addPathToScan(__DIR__.'/bin', false)
    ->addPathToScan(__DIR__.'/public', false)
    ->addPathToScan(__DIR__.'/migrations', false)
    // Reached through config files, the Flex recipes or the runtime rather than through a class reference in code.
    ->ignoreErrorsOnPackage('symfony/flex', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('symfony/runtime', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('symfony/dotenv', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('symfony/lock', [ErrorType::UNUSED_DEPENDENCY])
    // Components the container needs at runtime; only the tests name their classes.
    ->ignoreErrorsOnPackage('symfony/translation', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    ->ignoreErrorsOnPackage('symfony/yaml', [ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])
    // PHP extensions required at runtime without a direct call: brick/math picks BCMath when present, and the
    // polyfill-iconv replacement in composer.json relies on the native extension.
    ->ignoreErrorsOnExtension('ext-bcmath', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnExtension('ext-iconv', [ErrorType::UNUSED_DEPENDENCY]);
