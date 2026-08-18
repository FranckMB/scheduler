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
    // ⚑ Rector RÉÉCRIT en noms pleinement qualifiés par défaut ; CS-Fixer, lui, les
    // IMPORTE (`fully_qualified_strict_types` + `import_symbols`). Tant que Rector n'avait
    // rien à réécrire, les deux gates ne se croisaient jamais. Rector 2.6 a apporté une
    // nouvelle règle, donc des réécritures — et les deux outils se sont mis à se défaire
    // l'un l'autre sur les fichiers concernés (`Cookie::SAMESITE_STRICT` ↔
    // `\Symfony\Component\HttpFoundation\Cookie::SAMESITE_STRICT`), chacun rouge dans son
    // propre gate. On les ALIGNE une fois pour toutes plutôt que de trancher au cas par cas :
    // Rector importe désormais, comme CS-Fixer.
    // Bornée au strict nécessaire : on veut qu'il IMPORTE au lieu d'expanser, pas qu'il
    // fasse le ménage des imports au passage — `removeUnusedImports` touchait 8 fichiers
    // sans rapport avec la montée, et supprimer un import n'est jamais anodin quand un
    // docblock le référence encore.
    ->withImportNames(removeUnusedImports: false)
    ->withComposerBased(symfony: true);
