<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\SuperAdmin;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<SuperAdmin> */
final class SuperAdminProvider implements UserProviderInterface
{
    public function __construct(private readonly ManagerRegistry $registry) {}

    public function loadUserByIdentifier(string $identifier): SuperAdmin
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $row = $connection->fetchAssociative(
            'SELECT id, email, password_hash, totp_secret, enabled, last_login_at FROM super_admin WHERE LOWER(email) = LOWER(:email)',
            ['email' => trim($identifier)],
        );
        if (false === $row) {
            $exception = new UserNotFoundException;
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }

        return $this->hydrate($row);
    }

    public function loadById(string $id): SuperAdmin
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $row = $connection->fetchAssociative(
            'SELECT id, email, password_hash, totp_secret, enabled, last_login_at FROM super_admin WHERE id = :id',
            ['id' => $id],
        );
        if (false === $row) {
            throw new UserNotFoundException;
        }

        return $this->hydrate($row);
    }

    public function refreshUser(UserInterface $user): SuperAdmin
    {
        if (!$user instanceof SuperAdmin) {
            throw new UnsupportedUserException;
        }

        $refreshedUser = $this->loadById($user->getId());
        if (!$refreshedUser->isEnabled()) {
            $exception = new UserNotFoundException;
            $exception->setUserIdentifier($user->getUserIdentifier());
            throw $exception;
        }

        return $refreshedUser;
    }

    public function supportsClass(string $class): bool
    {
        return SuperAdmin::class === $class || is_subclass_of($class, SuperAdmin::class);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SuperAdmin
    {
        return new SuperAdmin(
            (string) $row['id'],
            (string) $row['email'],
            (string) $row['password_hash'],
            (string) $row['totp_secret'],
            (bool) $row['enabled'],
            null === $row['last_login_at'] ? null : new DateTimeImmutable((string) $row['last_login_at']),
        );
    }
}
