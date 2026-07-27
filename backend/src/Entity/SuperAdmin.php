<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use LogicException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Separate, non-tenant identity used only by the dedicated admin firewall. */
final class SuperAdmin implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private string $passwordHash,
        private readonly string $totpSecret,
        private readonly bool $enabled = true,
        private readonly ?DateTimeImmutable $lastLoginAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new LogicException('A super-admin email cannot be empty.');
        }

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getTotpSecret(): string
    {
        return $this->totpSecret;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_SUPER_ADMIN'];
    }

    /**
     * Même raison que `User::eraseCredentials()` : requise par `UserInterface` en
     * Symfony 7.x (dépréciée, retirée en 8.0), et le projet cible 7.4 alors que
     * `security-core` traîne en 8.0.x dans le lock. Ici l'enjeu est plus large que
     * la console : sans elle, un réalignement rend `SuperAdmin` non chargeable,
     * `SuperAdminProvider` non instanciable, et c'est le CONTENEUR ENTIER qui ne
     * démarre plus — toute l'API, pas seulement `/api/admin`.
     */
    public function eraseCredentials(): void {}
}
