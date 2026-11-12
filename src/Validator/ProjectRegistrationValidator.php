<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Project;
use App\Project\GitOriginNormalizer;
use App\Project\ProjectPathNormalizer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ProjectRegistrationValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ProjectPathNormalizer $pathNormalizer,
        private readonly GitOriginNormalizer $gitOriginNormalizer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ProjectRegistration) {
            throw new UnexpectedTypeException($constraint, ProjectRegistration::class);
        }

        if (!$value instanceof Project) {
            throw new UnexpectedValueException($value, Project::class);
        }

        $name = trim($value->name);
        if ('' === $name) {
            $this->context->buildViolation($constraint->blankNameMessage)
                ->atPath('name')
                ->addViolation();
        }

        $rootPath = trim($value->rootPath);
        if ('' === $rootPath) {
            $this->context->buildViolation($constraint->blankRootMessage)
                ->atPath('rootPath')
                ->addViolation();

            return;
        }

        $canonical = $this->pathNormalizer->canonicalizeRoot($rootPath);
        if (null === $canonical || !is_readable($canonical)) {
            $this->context->buildViolation($constraint->invalidRootMessage)
                ->atPath('rootPath')
                ->addViolation();

            return;
        }

        $origin = $this->gitOriginNormalizer->detectNormalizedOrigin($canonical);
        if ('' === $origin) {
            $origin = null;
        }

        // Persist normalized identity fields so UniqueEntity checks the same values.
        $value->assignIdentity(
            name: $name,
            nameNormalized: $this->pathNormalizer->normalizeName($name),
            rootPath: $rootPath,
            rootCanonical: $canonical,
            repositoryOrigin: $origin,
            now: $this->clock->now(),
        );
    }
}
