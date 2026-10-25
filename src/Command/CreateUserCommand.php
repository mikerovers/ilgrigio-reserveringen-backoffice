<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a new backoffice user (interactive: prompts for missing values)',
)]
final class CreateUserCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 8;
    private const MAX_PASSWORD_ATTEMPTS = 3;

    /** @var list<string> */
    private const ALLOWED_ROLES = ['ROLE_REGISTER', 'ROLE_CHECK_IN', 'ROLE_ADMIN'];

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
            ->addArgument('email', InputArgument::OPTIONAL, 'User email address (asked when omitted)')
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'Display name shown in the register app (asked when omitted)',
            )
            ->addOption(
                'role',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                sprintf(
                    'Role to grant, repeatable (%s). Skips the role selection prompt',
                    implode(', ', self::ALLOWED_ROLES),
                ),
            )
            ->setHelp(<<<'HELP'
Without arguments the command asks for the email, display name, password (hidden,
asked twice) and roles (multi-select). Pass --role one or more times to skip the
role prompt. The password is always entered interactively so it never ends up in
the shell history or the process list.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('app:user:create must be run interactively: the password is entered via a hidden prompt.');

            return Command::FAILURE;
        }

        try {
            $email = $this->askEmail($io, $input->getArgument('email'));
            $name = $this->askName($io, $input->getOption('name'));
            $roles = $this->askRoles($io, $input->getOption('role'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $plainPassword = $this->askPassword($io);
        if ($plainPassword === null) {
            $io->error('Passwords did not match. No user was created.');

            return Command::FAILURE;
        }

        $user = new User($email, '', $roles);
        $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->name = $name;

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User "%s" (%s) created with roles: %s', $name, $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }

    private function askEmail(SymfonyStyle $io, mixed $argument): string
    {
        $validator = function (?string $value): string {
            $value = trim((string) $value);
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new \RuntimeException('Please enter a valid email address.');
            }
            if ($this->userRepository->findOneByEmail($value) !== null) {
                throw new \RuntimeException(sprintf('A user with email "%s" already exists.', $value));
            }

            return $value;
        };

        if (is_string($argument) && $argument !== '') {
            return $validator($argument);
        }

        return $io->ask('Email address', null, $validator);
    }

    private function askName(SymfonyStyle $io, mixed $option): string
    {
        $validator = function (?string $value): string {
            $value = trim((string) $value);
            if ($value === '') {
                throw new \RuntimeException('The display name cannot be empty.');
            }

            return $value;
        };

        if (is_string($option) && trim($option) !== '') {
            return $validator($option);
        }

        return $io->ask('Display name (shown in the register app)', null, $validator);
    }

    /**
     * @param list<string> $option values from --role (repeatable); empty when not given
     *
     * @return list<string>
     */
    private function askRoles(SymfonyStyle $io, array $option): array
    {
        if ($option !== []) {
            return $this->validateRoles($option);
        }

        $answer = $io->choice(
            'Roles (use arrows and space to toggle, enter to confirm)',
            self::ALLOWED_ROLES,
            'ROLE_REGISTER',
            true,
        );

        /** @var list<string>|string $answer */
        return $this->validateRoles(is_array($answer) ? $answer : explode(',', $answer));
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function validateRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_map('trim', $roles)));
        $invalid = array_diff($roles, self::ALLOWED_ROLES);
        if ($invalid !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown role(s): %s. Allowed: %s.',
                implode(', ', $invalid),
                implode(', ', self::ALLOWED_ROLES),
            ));
        }

        return $roles;
    }

    private function askPassword(SymfonyStyle $io): ?string
    {
        for ($attempt = 1; $attempt <= self::MAX_PASSWORD_ATTEMPTS; ++$attempt) {
            $password = (string) $io->askHidden('Password');
            if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $io->warning(sprintf('The password must be at least %d characters long.', self::MIN_PASSWORD_LENGTH));
                continue;
            }

            $confirmation = (string) $io->askHidden('Repeat password');
            if (hash_equals($password, $confirmation)) {
                return $password;
            }

            $io->warning('The passwords do not match.');
        }

        return null;
    }
}
