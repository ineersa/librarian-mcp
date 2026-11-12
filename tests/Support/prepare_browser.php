<?php

declare(strict_types=1);

use App\Entity\User;
use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

require dirname(__DIR__).'/bootstrap.php';

if ('test' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV'))) {
    throw new LogicException('Synthetic admin preparation requires APP_ENV=test.');
}

$kernel = new Kernel('test', false);
try {
    $kernel->boot();
    $projectDir = $kernel->getProjectDir();
    $databaseUrl = (string) ($_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '');
    $expectedPrefix = 'sqlite:///'.$projectDir.'/var/browser-live';
    if (!str_starts_with($databaseUrl, $expectedPrefix)) {
        throw new LogicException(\sprintf(
            'Refusing to mutate users outside the isolated browser-live SQLite path. Expected DATABASE_URL under %s, got %s.',
            $expectedPrefix,
            '' === $databaseUrl ? '<empty>' : $databaseUrl,
        ));
    }

    $container = $kernel->getContainer();
    $doctrine = $container->get('doctrine');
    if (!$doctrine instanceof ManagerRegistry) {
        throw new LogicException('Doctrine is unavailable for synthetic admin preparation.');
    }

    $manager = $doctrine->getManagerForClass(User::class) ?? throw new LogicException('The user manager is unavailable.');
    $hasher = $container->get(UserPasswordHasherInterface::class);
    if (!$hasher instanceof UserPasswordHasherInterface) {
        throw new LogicException('The password hasher is unavailable.');
    }

    $repository = $manager->getRepository(User::class);
    $email = 'admin@test.com';
    $password = 'admin';
    $user = $repository->findOneBy(['email' => $email]);
    if (!$user instanceof User) {
        $user = new User();
        $user->email = $email;
        $manager->persist($user);
    }

    $user->roles = ['ROLE_ADMIN', 'ROLE_MCP'];
    $user->password = $hasher->hashPassword($user, $password);
    $user->touch();
    $manager->flush();

    echo "Prepared synthetic admin@test.com in isolated browser-live storage; owner data untouched.\n";
} finally {
    $kernel->shutdown();
}
