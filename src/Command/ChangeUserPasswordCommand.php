<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:change-password',
    description: 'Changes the password of an existing backoffice user',
)]
final class ChangeUserPasswordCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the user whose password should change')
            ->setHelp(<<<'HELP'
The new password is requested interactively (hidden input, asked twice) so it
never ends up in the shell history or the process list.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');

        $user = $this->userRepository->findOneByEmail($email);
        if ($user === null) {
            $io->error(sprintf('No user found with email "%s".', $email));

            return Command::FAILURE;
        }

        $plainPassword = (string) $io->askHidden('New password');
        if (mb_strlen($plainPassword) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('The password must be at least %d characters long.', self::MIN_PASSWORD_LENGTH));

            return Command::FAILURE;
        }

        $confirmation = (string) $io->askHidden('Repeat new password');
        if (!hash_equals($plainPassword, $confirmation)) {
            $io->error('The passwords do not match.');

            return Command::FAILURE;
        }

        $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);

        $this->entityManager->flush();

        $io->success(sprintf('Password changed for user "%s".', $user->email));

        return Command::SUCCESS;
    }
}
