<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CouponRepository;

final class CouponCodeGenerator
{
    /** Lowercase alphanumerics, excluding visually ambiguous characters (0/o, 1/l/i). */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    private const RANDOM_LENGTH = 6;

    private const MAX_ATTEMPTS_PER_CODE = 50;

    public function __construct(
        private readonly CouponRepository $couponRepository,
    ) {
    }

    /**
     * @param string[] $existingBatchCodes codes already reserved elsewhere in the same batch
     *
     * @return string[]
     */
    public function generate(string $prefix, int $displayAmount, int $count, array $existingBatchCodes = []): array
    {
        $reserved = array_flip($existingBatchCodes);
        $codes = [];

        for ($i = 0; $i < $count; ++$i) {
            $code = $this->generateUniqueCode($prefix, $displayAmount, $reserved);
            $codes[] = $code;
            $reserved[$code] = true;
        }

        return $codes;
    }

    /**
     * @param array<string, bool> $reserved
     */
    private function generateUniqueCode(string $prefix, int $displayAmount, array $reserved): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS_PER_CODE; ++$attempt) {
            $code = sprintf('%s-%d-%s', $prefix, $displayAmount, $this->randomSuffix());

            if (isset($reserved[$code])) {
                continue;
            }

            if ($this->couponRepository->findOneByCode($code) === null) {
                return $code;
            }
        }

        throw new \RuntimeException(
            'Unable to generate a unique coupon code after ' . self::MAX_ATTEMPTS_PER_CODE . ' attempts.',
        );
    }

    private function randomSuffix(): string
    {
        $alphabetLength = \strlen(self::ALPHABET);
        $suffix = '';

        for ($i = 0; $i < self::RANDOM_LENGTH; ++$i) {
            $suffix .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $suffix;
    }
}
