<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withPhpVersion(80300)
    ->withPreparedSets(codeQuality: true, typeDeclarations: true)
    ->withComposerBased(symfony: true);
