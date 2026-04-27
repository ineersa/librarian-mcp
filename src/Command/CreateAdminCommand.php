<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
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
    name: 'app:user:create-admin',
    description: 'Create an admin user',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email address')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Admin password (will be prompted if not provided)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        // Check if user already exists
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            $io->error(\sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        // Get password
        $plainPassword = $input->getOption('password');
        if (null === $plainPassword) {
            $plainPassword = $io->askHidden('Enter password for the admin user');
        }

        if ('' === $plainPassword) {
            $io->error('Password cannot be empty.');

            return Command::FAILURE;
        }

        // Create user
        $user = new User();
        $user->email = $email;
        $user->roles = ['ROLE_ADMIN'];
        $user->password = $this->passwordHasher->hashPassword($user, $plainPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Admin user "%s" created successfully.', $email));

        return Command::SUCCESS;
    }
}
