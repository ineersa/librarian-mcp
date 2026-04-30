<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

/**
 * Symbol type filter for semantic search results.
 * Mirrors vera's --type values.
 */
enum SymbolType: string
{
    case Function = 'function';
    case Method = 'method';
    case Class_ = 'class';
    case Struct = 'struct';
    case Enum = 'enum';
    case Trait = 'trait';
    case Interface = 'interface';
    case TypeAlias = 'type_alias';
    case Constant = 'constant';
    case Variable = 'variable';
    case Module = 'module';
    case Block = 'block';
}
