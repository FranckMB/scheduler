<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    // `tests/` AUSSI : la convention de style est valable partout, et la passe
    // P4-24 a restylé 40+ fichiers de test. Limiter le périmètre à `src/` aurait
    // laissé le style diverger dans `tests/` sans que la CI le voie — exactement
    // l'état que P4-24 corrigeait.
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpVersion(80400)
    ->withPreparedSets(codeQuality: true, typeDeclarations: true)
    // `withComposerBased` charge les sets d'après les versions INSTALLÉES. Le
    // stack étant homogène en 7.4 (P4-31), aucun set Symfony 8.0 ne s'active :
    // plus aucune exception à déclarer ici. La précédente
    // (`RemoveEraseCredentialsRector`) n'existait que parce que `security-core`
    // avait dérivé en 8.0 ; Rector signalait lui-même la règle comme « never
    // registered » une fois l'alignement fait. Ce qui protège désormais
    // `eraseCredentials`, ce n'est plus un skip mais deux NR : le stack reste
    // en 7.4 (`SymfonyStackAlignmentTest`) et la méthode reste présente
    // (`UserInterfaceContractTest`).
    ->withComposerBased(symfony: true);
