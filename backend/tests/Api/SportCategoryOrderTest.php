<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Service\Basketball\CategoryCatalog;
use App\Tests\VerifiesRegistration;
use DoctrineMigrations\Version20260801120000;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-36 — L'ORDRE DES CATÉGORIES EST SERVI, IL NE SE DEVINE PAS.
 *
 * Constat du 2026-08-01 : rien ne triait les catégories. Le provider générique n'ajoutait
 * qu'un `ORDER BY e.id`, or les ids sont des UUID v4 — la collection sortait donc dans un
 * ordre ALÉATOIRE, différent d'un club à l'autre, pendant que la colonne `sortOrder` du
 * catalogue ne servait à rien. Le sélecteur de catégories du wizard en héritait.
 *
 * Ce test épingle les DEUX moitiés du correctif, indissociables : le catalogue est rangé du
 * plus âgé au plus jeune (retour terrain), et le serveur trie réellement dessus. Réordonner
 * le catalogue sans le tri ne se verrait pas ; trier sans le catalogue servirait l'ancien
 * ordre. C'est pourquoi une seule assertion les tient ensemble.
 */
#[Group('integration')]
final class SportCategoryOrderTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    public function testCategoriesAreServedOldestToYoungest(): void
    {
        $token = $this->register();

        $this->client->request('GET', '/api/sport_categories', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $names = array_column($data['member'] ?? $data['hydra:member'] ?? [], 'name');

        // Le catalogue FAIT foi : le test ne recopie pas l'ordre attendu à la main, sinon
        // les deux dériveraient et personne ne le verrait.
        self::assertSame(array_column(CategoryCatalog::categories(), 'name'), $names, 'la collection doit sortir dans l\'ordre du catalogue');

        // …et l'ordre du catalogue est bien celui demandé par le terrain. Sans cette
        // seconde assertion, inverser le catalogue passerait inaperçu (les deux côtés
        // bougeraient ensemble).
        self::assertSame('Vétéran', $names[0], 'le plus âgé en tête');
        self::assertSame('U5', $names[9], 'puis l\'axe des âges jusqu\'au plus jeune');
        self::assertSame(['Baby basket', 'Loisir'], \array_slice($names, 10), 'les catégories sans âge ferment la liste');
    }

    /**
     * La MIGRATION est la seule pièce irréversible du lot, et le test ci-dessus ne
     * l'exerçait pas : il inscrit un club NEUF, dont les catégories sont semées depuis le
     * catalogue — supprimer la migration l'aurait laissé vert pendant que tout club
     * préexistant gardait l'ancienne numérotation (revue #347).
     *
     * Ce qu'on garde ici est la DÉRIVE, le vrai risque de régression : la table de
     * renumérotation de la migration et le catalogue doivent dire la même chose. Réordonner
     * l'un sans l'autre laisserait les clubs existants et les nouveaux dans deux ordres
     * différents — le désordre même que le lot supprime.
     *
     * ⚠ Ce que ce test ne garde PAS, et qui reste à la charge du contrôle manuel : que la
     * migration ait réellement tourné sur un environnement donné.
     */
    public function testMigrationRenumberingMatchesTheCatalogue(): void
    {
        $expected = [];
        foreach (CategoryCatalog::categories() as $category) {
            $expected[$category['name']] = $category['sortOrder'];
        }

        self::assertSame($expected, Version20260801120000::ORDER, 'la migration et le catalogue doivent numéroter à l\'identique');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function register(): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'cat' . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'C', 'lastName' => 'At', 'ara' => strtoupper($suffix), 'club_name' => 'Cat Club', 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        return $token;
    }
}
