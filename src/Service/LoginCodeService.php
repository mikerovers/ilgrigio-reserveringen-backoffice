<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Customer;
use App\Entity\LoginCode;
use App\Message\SendLoginCodeMessage;
use App\Repository\CustomerRepository;
use App\Repository\LoginCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/** Passwordless customer login: issues and verifies one-time email codes. */
final class LoginCodeService
{
    public const CODE_TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private CustomerRepository $customerRepository,
        private LoginCodeRepository $loginCodeRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Sends a code when a customer with this email exists. Silent otherwise, so
     * callers cannot be used to find out which addresses are registered.
     */
    public function requestCode(string $email): void
    {
        $email = self::normaliseEmail($email);
        $customer = $this->customerRepository->findOneByEmailIgnoreCase($email);

        if ($customer === null || $customer->orders->isEmpty()) {
            $this->logger->info('Login code requested for unknown email');

            return;
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->loginCodeRepository->invalidateOpenCodes($email, $now);
        $this->entityManager->persist(new LoginCode(
            $email,
            $this->hash($email, $code),
            $now->modify('+' . self::CODE_TTL_MINUTES . ' minutes'),
        ));
        $this->entityManager->flush();

        $this->messageBus->dispatch(new SendLoginCodeMessage($email, $code, self::CODE_TTL_MINUTES));

        $this->logger->info('Login code issued', ['customer_id' => (string) $customer->id]);
    }

    /** Returns the customer when the code is valid; consumes the code on success. */
    public function verify(string $email, string $code): ?Customer
    {
        $email = self::normaliseEmail($email);
        $code = preg_replace('/\s+/', '', $code) ?? '';
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $loginCode = $this->loginCodeRepository->findActiveByEmail($email, $now);

        if ($loginCode === null || $loginCode->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        if (!hash_equals($loginCode->codeHash, $this->hash($email, $code))) {
            ++$loginCode->attempts;
            $this->entityManager->flush();

            $this->logger->warning('Invalid login code submitted', ['attempts' => $loginCode->attempts]);

            return null;
        }

        $loginCode->usedAt = $now;
        $this->entityManager->flush();

        return $this->customerRepository->findOneByEmailIgnoreCase($email);
    }

    private function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . '|' . $code, $this->secret);
    }
}
