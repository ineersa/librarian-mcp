<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

/**
 * Coarse corpus scope for limiting search/grep to a file category.
 * Mirrors vera's --scope values.
 */
enum SearchScope: string
{
    case Source = 'source';
    case Docs = 'docs';
    case Runtime = 'runtime';
    case All = 'all';
}
