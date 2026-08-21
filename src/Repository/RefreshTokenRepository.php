<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshTokenRepository as BaseRefreshTokenRepository;

/**
 * @extends BaseRefreshTokenRepository<RefreshToken>
 */
class RefreshTokenRepository extends BaseRefreshTokenRepository
{
}
