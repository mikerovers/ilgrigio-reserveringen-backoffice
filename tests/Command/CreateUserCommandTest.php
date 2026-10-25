<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserCommandTest extends KernelTestCase
{
    private const EMAIL = 'create-user-command-test@example.com';
    private const PASSWORD = 'secret-password';

    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp();
        $this->tester = new CommandTester((new Application($kernel))->find('app:user:create'));
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM "user" WHERE email = :e', ['e' => self::EMAIL]);
        $this->em->clear();
    }

    public function testCreatesUserWithNameRoleAndPasswordPrompts(): void
    {
        // password, repeat password
        $this->tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute([
            'email' => self::EMAIL,
            '--name' => '  Rita Register ',
            '--role' => ['ROLE_REGISTER'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        self::assertSame('Rita Register', $user->name);
        self::assertContains('ROLE_REGISTER', $user->getRoles());
    }

    public function testRejectsUnknownRole(): void
    {
        $this->tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute([
            'email' => self::EMAIL,
            '--name' => 'Rita',
            '--role' => ['ROLE_NOPE'],
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('ROLE_NOPE', $this->tester->getDisplay());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]));
    }

    public function testNoUserIsCreatedWhenPasswordsDoNotMatch(): void
    {
        // three mismatching attempts exhaust the retry budget
        $this->tester->setInputs([
            'first-password', 'other-password',
            'first-password', 'other-password',
            'first-password', 'other-password',
        ]);

        $status = $this->tester->execute([
            'email' => self::EMAIL,
            '--name' => 'Rita',
            '--role' => ['ROLE_REGISTER'],
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]));
    }
}
