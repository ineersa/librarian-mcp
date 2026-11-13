<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Normalizes project identity fields and validates the selected root directory.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ProjectRegistration extends Constraint
{
    public string $blankNameMessage = 'Project name is required.';
    public string $blankRootMessage = 'Project directory is required.';
    public string $invalidRootMessage = 'Project directory must be an existing readable directory.';

    #[HasNamedArguments]
    public function __construct(
        ?string $blankNameMessage = null,
        ?string $blankRootMessage = null,
        ?string $invalidRootMessage = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->blankNameMessage = $blankNameMessage ?? $this->blankNameMessage;
        $this->blankRootMessage = $blankRootMessage ?? $this->blankRootMessage;
        $this->invalidRootMessage = $invalidRootMessage ?? $this->invalidRootMessage;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
