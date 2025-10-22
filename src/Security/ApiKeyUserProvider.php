<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class ApiKeyUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // Return a simple in-memory user for API key authentication
        return new InMemoryUser($identifier, null, ['ROLE_API']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        // This is stateless authentication, no need to refresh
        throw new UnsupportedUserException('API key authentication is stateless');
    }

    public function supportsClass(string $class): bool
    {
        return InMemoryUser::class === $class;
    }
}
