<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ADR-0002 : le nom PUBLIC du planning vit sur le Plan (inv. 12) — seul champ que
 * le gestionnaire édite. Type, période, déclencheur et pointeur ne sont jamais
 * pilotés par le client : le plan naît par provisioning et son pointeur bouge en
 * validant/rouvrant une version.
 */
class SchedulePlanInput
{
    // normalizer 'trim' : sans lui «   » passe NotBlank puis se stocke vide, et
    // Length s'évaluerait sur une valeur différente de celle réellement écrite.
    #[Assert\NotBlank(message: 'Le nom du planning ne peut pas être vide.', normalizer: 'trim')]
    #[Assert\Length(max: 180, maxMessage: 'Le nom du planning ne peut pas dépasser {{ limit }} caractères.', normalizer: 'trim')]
    #[Groups(['write'])]
    public string $name = '';
}
