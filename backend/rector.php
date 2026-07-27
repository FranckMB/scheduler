<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony80\Rector\Class_\RemoveEraseCredentialsRector;

return RectorConfig::configure()
    // `tests/` AUSSI : la convention de style est valable partout, et la passe
    // P4-24 a restylé 40+ fichiers de test. Limiter le périmètre à `src/` aurait
    // laissé le style diverger dans `tests/` sans que la CI le voie — exactement
    // l'état que P4-24 corrigeait.
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpVersion(80400)
    ->withPreparedSets(codeQuality: true, typeDeclarations: true)
    ->withComposerBased(symfony: true)
    // Seule exception au « on adopte le standard Rector » : cette règle lit la
    // version INSTALLÉE de security-core (transitivement en 8.0.x) alors que le
    // projet cible 7.4 (`extra.symfony.require`). Elle supprime
    // `User::eraseCredentials()`, requise par `UserInterface` en 7.x — un
    // réalignement de la dépendance rendrait alors `User` non instanciable
    // (fatal, toute l'auth tombe). À retirer le jour où le stack passe en 8.0.
    ->withSkip([RemoveEraseCredentialsRector::class]);
