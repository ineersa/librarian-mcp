<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Model;

/**
 * OM shared-model providers exposed in EasyAdmin.
 */
enum MemoryModelProvider: string
{
    case LlamaCpp = 'llama_cpp';
    case OpencodeGo = 'opencode-go';
    case Zai = 'zai';

    public function label(): string
    {
        return match ($this) {
            self::LlamaCpp => 'Local llama.cpp',
            self::OpencodeGo => 'OpenCode Go',
            self::Zai => 'Z.ai',
        };
    }

    public function leavesHost(): bool
    {
        return match ($this) {
            self::LlamaCpp => false,
            self::OpencodeGo, self::Zai => true,
        };
    }
}
