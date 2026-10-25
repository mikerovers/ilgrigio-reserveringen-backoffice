<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendLoginCodeMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final class SendLoginCodeMessageHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private string $fromEmail = 'noreply@example.com',
        private string $fromName = 'Il Grigio',
    ) {
    }

    public function __invoke(SendLoginCodeMessage $message): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($message->email)
            ->subject($this->translator->trans('email.login_code.subject'))
            ->htmlTemplate('email/login_code.html.twig')
            ->textTemplate('email/login_code.txt.twig')
            ->context([
                'code' => $message->code,
                'ttl_minutes' => $message->ttlMinutes,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send login code email', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->logger->info('Login code email sent');
    }
}
