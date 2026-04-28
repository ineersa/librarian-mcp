<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Library;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LibraryValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    // --- gitUrl validation ---

    public function testGitUrlValidHttps(): void
    {
        $library = $this->createValidLibrary();
        $library->setGitUrl('https://github.com/symfony/symfony-docs');

        $violations = $this->validator->validateProperty($library, 'gitUrl');
        $this->assertCount(0, $violations);
    }

    public function testGitUrlValidWithGitSuffix(): void
    {
        $library = $this->createValidLibrary();
        $library->setGitUrl('https://github.com/symfony/symfony-docs.git');

        $violations = $this->validator->validateProperty($library, 'gitUrl');
        $this->assertCount(0, $violations);
    }

    public function testGitUrlRejectsEmpty(): void
    {
        $library = $this->createValidLibrary();
        $library->setGitUrl('');

        $violations = $this->validator->validateProperty($library, 'gitUrl');
        $this->assertGreaterThanOrEqual(1, $violations->count());
    }

    public function testGitUrlRejectsHttp(): void
    {
        $library = $this->createValidLibrary();
        $library->setGitUrl('http://github.com/symfony/symfony-docs');

        $violations = $this->validator->validateProperty($library, 'gitUrl');
        $this->assertGreaterThanOrEqual(1, $violations->count());
    }

    public function testGitUrlRejectsSsh(): void
    {
        $library = $this->createValidLibrary();
        $library->setGitUrl('git@github.com:symfony/symfony-docs.git');

        $violations = $this->validator->validateProperty($library, 'gitUrl');
        $this->assertGreaterThanOrEqual(1, $violations->count());
    }

    public function testGitUrlRejectsNonGithub(): void
    {
        $library = $this->createValidLibrary();
        $library->setGitUrl('https://gitlab.com/symfony/symfony-docs');

        $violations = $this->validator->validateProperty($library, 'gitUrl');
        $this->assertGreaterThanOrEqual(1, $violations->count());
    }

    // --- branch validation ---

    public function testBranchRejectsEmpty(): void
    {
        $library = $this->createValidLibrary();
        $library->setBranch('');

        $violations = $this->validator->validateProperty($library, 'branch');
        $this->assertGreaterThanOrEqual(1, $violations->count());
    }

    public function testBranchAcceptsMain(): void
    {
        $library = $this->createValidLibrary();
        $library->setBranch('main');

        $violations = $this->validator->validateProperty($library, 'branch');
        $this->assertCount(0, $violations);
    }

    // --- name validation ---

    public function testNameAllowsEmptyForAutoDerivation(): void
    {
        $library = $this->createValidLibrary();
        $library->setName('');

        $violations = $this->validator->validateProperty($library, 'name');
        $this->assertCount(0, $violations);
    }

    // --- slug validation ---

    public function testSlugAllowsEmptyForAutoGeneration(): void
    {
        $library = $this->createValidLibrary();
        $library->setSlug('');

        $violations = $this->validator->validateProperty($library, 'slug');
        $this->assertCount(0, $violations);
    }

    // --- description validation ---

    public function testDescriptionRejectsEmpty(): void
    {
        $library = $this->createValidLibrary();
        $library->setDescription('');

        $violations = $this->validator->validateProperty($library, 'description');
        $this->assertGreaterThanOrEqual(1, $violations->count());
    }

    private function createValidLibrary(): Library
    {
        $library = new Library();
        $library->setName('test/repo');
        $library->setSlug('test-repo');
        $library->setGitUrl('https://github.com/test/repo');
        $library->setBranch('main');
        $library->setDescription('A test library description.');

        return $library;
    }
}
