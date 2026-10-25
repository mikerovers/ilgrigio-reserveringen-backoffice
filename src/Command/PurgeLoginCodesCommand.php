<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LoginCodeRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:login-codes:purge',
    description: 'Deletes customer login codes that expired more than a day ago',
)]
final class PurgeLoginCodesCommand extends Command
{
    public function __construct(
        private readonly LoginCodeRepository $loginCodeRepository,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $deleted = $this->loginCodeRepository->purgeOlderThan(
            \DateTimeImmutable::createFromInterface($this->clock->now())
                ->setTimezone(new \DateTimeZone('UTC'))
                ->modify('-1 day')
        );

        $io->success(sprintf('Deleted %d expired login code(s).', $deleted));

        return Command::SUCCESS;
    }
}
