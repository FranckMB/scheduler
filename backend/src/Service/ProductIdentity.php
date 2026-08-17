<?php

declare(strict_types=1);

namespace App\Service;

/**
 * L'IDENTITÉ du produit — maison unique du nom commercial (« Amateo ») et de
 * l'éditeur (« Maratech ») lus par un humain (P5-15). Les deux valeurs viennent
 * de l'environnement (`APP_PRODUCT_NAME`, `APP_PUBLISHER_NAME`, bindées dans
 * `config/services.yaml`) : un futur changement de nom se fait EN UN POINT, il
 * ne rouvre pas la chasse aux littéraux dans les sujets d'e-mail, les libellés
 * d'écran ou l'URI TOTP.
 *
 * Même patron que `MailFrom` : les défauts ci-dessous ne sont PAS une seconde
 * configuration à maintenir — ils servent aux tests unitaires qui construisent
 * le service à la main. En application, le bind gagne toujours.
 *
 * Deux valeurs, une seule maison : le nom produit et le nom éditeur sont
 * distincts (le second est le responsable de traitement RGPD, cf. page
 * Confidentialité) mais voyagent ensemble — un service unique évite de threader
 * deux scalaires dans les sept points d'appel.
 */
final readonly class ProductIdentity
{
    public function __construct(
        private string $productName = 'Amateo',
        private string $publisherName = 'Maratech',
    ) {}

    /** Le nom commercial du produit — « Amateo ». */
    public function name(): string
    {
        return $this->productName;
    }

    /** L'éditeur (responsable de traitement RGPD) — « Maratech ». */
    public function publisher(): string
    {
        return $this->publisherName;
    }
}
