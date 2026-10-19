<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\User;

final class MeResponse
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public array $roles,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            id: (string) $user->id,
            email: $user->email,
            name: $user->name,
            roles: $user->getRoles(),
        );
    }
}
