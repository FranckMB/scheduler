<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ProductIdentity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P5-15 — le nom produit a UNE maison : `ProductIdentity`, alimenté par
 * `APP_PRODUCT_NAME`/`APP_PUBLISHER_NAME`.
 *
 * Le test qui compte est le second : il interdit que l'ancien nom commercial
 * (« ClubScheduler ») revienne EN DUR dans `src/`. C'est précisément ce qui a
 * rendu le renommage coûteux — des libellés à retrouver un par un, dont
 * plusieurs au milieu d'un contrôleur de 900 lignes. Un « ClubScheduler »
 * littéral rouvre la chasse : il échoue ici, en nommant le fichier et la ligne.
 *
 * QUATRE littéraux techniques restent, EXCLUS par écrit — les renommer ne
 * corrige pas un texte lu par un humain, il CASSE des données déjà écrites :
 *  - `clubscheduler-*.dump`  : préfixe des SAUVEGARDES ; le changer rend
 *    invisibles les dumps existants (rétention, couverture, restauration).
 *  - `clubscheduler.admin_job` : clé du VERROU consultatif PostgreSQL ; deux
 *    versions déployées prendraient deux verrous et lanceraient deux fois le job.
 *  - `clubscheduler_*` : identifiants SQL / noms de base (la base applicative
 *    `clubscheduler_test`, la base de restauration `clubscheduler_restore_*`) —
 *    INFRA, au même titre que POSTGRES_DB / le rôle SQL `clubscheduler`.
 *  - `clubscheduler-slot:` (graine d'un UUIDv5 des créneaux) : vit dans
 *    `engine/`, HORS de la racine balayée ici — cité pour mémoire.
 * Une exclusion sans raison écrite serait une régression déguisée : ici chaque
 * ligne conservée porte sa raison ci-dessus.
 */
#[Group('phase1')]
final class ProductIdentityTest extends TestCase
{
    /**
     * Sous-chaînes techniques tolérées (cf. docblock) : une ligne qui en
     * contient une garde son littéral `clubscheduler` — ce n'est pas du texte
     * lu par un humain, c'est une clé de persistance ou d'infrastructure.
     *
     * @var list<string>
     */
    private const array TECHNICAL_EXCEPTIONS = [
        'clubscheduler-',          // préfixe des fichiers de sauvegarde (*.dump)
        'clubscheduler.admin_job', // verrou consultatif PostgreSQL
        'clubscheduler_',          // noms de base / identifiants SQL (infra)
    ];

    /**
     * Fichiers de CONFIGURATION d'infrastructure qui nomment le rôle SQL admin
     * `clubscheduler` — un identifiant de base, pas un nom de produit : le renommer
     * demanderait de recréer le rôle en production. Exclus par FICHIER (et non par
     * sous-chaîne) pour qu'aucun titre affiché ne se glisse derrière l'exception.
     *
     * @var list<string>
     */
    private const array INFRA_CONFIG_FILES = [
        'config/packages/doctrine.yaml',
        'config/packages/doctrine_migrations.yaml',
    ];

    public function testIdentityCarriesTheConfiguredNames(): void
    {
        $identity = new ProductIdentity('Marque', 'Éditeur');

        self::assertSame('Marque', $identity->name());
        self::assertSame('Éditeur', $identity->publisher());
    }

    public function testOldProductNameIsNotHardCodedInSource(): void
    {
        $offenders = [];
        $backend = \dirname(__DIR__, 3);

        // `src/` ET `config/` : le titre exposé par `/api/docs` vivait dans
        // `config/packages/api_platform.yaml` et a survécu au renommage parce que
        // le balayage s'arrêtait à `src/`. Un angle mort de garde EST une régression
        // en attente — le texte lu par un humain ne vit pas que dans du PHP.
        foreach ([$backend . '/src', $backend . '/config'] as $root) {
            /** @var iterable<string, SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $path => $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['php', 'yaml', 'yml'], true)) {
                    continue;
                }

                $relative = str_replace($backend . '/', '', $path);
                if (\in_array($relative, self::INFRA_CONFIG_FILES, true)) {
                    continue;
                }

                $contents = file_get_contents($path);
                self::assertIsString($contents);

                foreach (explode("\n", $contents) as $number => $line) {
                    if (false === stripos($line, 'clubscheduler')) {
                        continue;
                    }
                    foreach (self::TECHNICAL_EXCEPTIONS as $allowed) {
                        if (str_contains($line, $allowed)) {
                            continue 2;
                        }
                    }
                    $offenders[] = \sprintf('%s:%d', str_replace($backend . '/', '', $path), $number + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'Ancien nom produit codé en dur : passer par ProductIdentity (APP_PRODUCT_NAME/APP_PUBLISHER_NAME) pour le texte lu par un humain.');
    }
}
