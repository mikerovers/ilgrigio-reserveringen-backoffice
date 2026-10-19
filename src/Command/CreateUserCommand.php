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
    description: 'Creates a new backoffice user with a hashed password',
)]
final class CreateUserCommand extends Command
{
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
            ->addArgument('email', InputArgument::REQUIRED, 'User email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain-text password (will be hashed)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name shown in the register app (required)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Additional role to grant (e.g. ROLE_ADMIN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $plainPassword */
        $plainPassword = $input->getArgument('password');
        /** @var string|null $role */
        $role = $input->getOption('role');
        /** @var string|null $name */
        $name = $input->getOption('name');

        $name = $name === null ? '' : trim($name);
        if ($name === '') {
            $io->error('The --name option is required (e.g. --name="Rita Register").');

            return Command::FAILURE;
        }

        if ($this->userRepository->findOneByEmail($email) !== null) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $roles = $role !== null ? [$role] : [];

        $user = new User($email, '', $roles);
        $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->name = $name;

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User "%s" (%s) created with roles: %s', $name, $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
