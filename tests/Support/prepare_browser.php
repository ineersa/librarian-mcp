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

$projectDir = dirname(__DIR__, 2);
$databasePath = $projectDir.'/var/browser-live/application.db';
$databaseUrl = (string) ($_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '');
if ('sqlite:///'.$databasePath !== $databaseUrl) {
    throw new LogicException('Synthetic admin preparation requires the exact browser-live DATABASE_URL.');
}
foreach ([$projectDir.'/var', dirname($databasePath), $databasePath] as $path) {
    if (is_link($path)) {
        throw new LogicException('Refusing symlinked browser storage.');
    }
}

$kernel = new Kernel('test', true);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $doctrine = $container->get('doctrine');
    if (!$doctrine instanceof ManagerRegistry) {
        throw new LogicException('Doctrine is unavailable for synthetic admin preparation.');
    }
    if ($databasePath !== $doctrine->getConnection()->getParams()['path']) {
        throw new LogicException('Doctrine did not resolve the isolated browser database.');
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
