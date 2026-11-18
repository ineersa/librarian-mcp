<?php

declare(strict_types=1);

namespace App\ObservationalMemory\Ingest;

use App\Entity\MemorySourceKind;
use App\Memory\ClaudeTranscriptReader;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Validated `POST /api/om/v1/observe` signal (contract version 1).
 *
 * Content-free: the client says "observe this session through end_seq" and Librarian
 * reads the range from the session journal itself, like the OM observe-boundary job.
 */
#[Exclude]
final readonly class MemoryObserveRequest
{
    public const int CONTRACT_VERSION = 1;
    private const int MAX_BODY_BYTES = 4096;
    private const int MAX_IDENTIFIER_BYTES = 255;

    private function __construct(
        public MemorySourceKind $sourceKind,
        public string $sessionId,
        public ?string $parentSessionId,
        public int $endSeq,
        public MemoryTerminalStatus $terminalStatus,
    ) {
    }

    public static function fromJson(string $body): self
    {
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            throw self::invalid('Body exceeds the size limit.');
        }
        try {
            $data = json_decode($body, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid('Body must be valid JSON.');
        }
        if (!\is_array($data)) {
            throw self::invalid('Body must be a JSON object.');
        }
        if (self::CONTRACT_VERSION !== ($data['contract_version'] ?? null)) {
            throw new MemoryIngestException(MemoryIngestErrorCode::UnsupportedContractVersion, 'Only contract_version 1 is supported.');
        }

        $sourceKind = MemorySourceKind::tryFrom(\is_string($data['source_kind'] ?? null) ? $data['source_kind'] : '')
            ?? throw self::invalid('source_kind must be one of: '.implode(', ', array_column(MemorySourceKind::cases(), 'value')).'.');

        $endSeq = $data['end_seq'] ?? null;
        if (!\is_int($endSeq) || $endSeq < 1) {
            throw self::invalid('end_seq must be an integer >= 1.');
        }

        $terminalStatus = MemoryTerminalStatus::Completed;
        if (\array_key_exists('terminal_status', $data)) {
            $terminalStatus = MemoryTerminalStatus::tryFrom(\is_string($data['terminal_status']) ? $data['terminal_status'] : '')
                ?? throw self::invalid('terminal_status must be one of: '.implode(', ', array_column(MemoryTerminalStatus::cases(), 'value')).'.');
        }

        $sessionId = self::identifier($data['session_id'] ?? null, 'session_id');
        if (MemorySourceKind::ClaudeCode === $sourceKind && 1 !== preg_match(ClaudeTranscriptReader::SESSION_ID_PATTERN, $sessionId)) {
            throw self::invalid('claude_code session_id must be a lowercase Claude session UUID.');
        }

        return new self(
            $sourceKind,
            $sessionId,
            null === ($data['parent_session_id'] ?? null) ? null : self::identifier($data['parent_session_id'], 'parent_session_id'),
            $endSeq,
            $terminalStatus,
        );
    }

    private static function identifier(mixed $value, string $field): string
    {
        // Same constraints as the journal reader's session directory names.
        if (!\is_string($value) || '' === $value || trim($value) !== $value || \strlen($value) > self::MAX_IDENTIFIER_BYTES
            || 1 === preg_match('#[\x00-\x1F\x7F/\\\\]#', $value) || '.' === $value || '..' === $value) {
            throw self::invalid(\sprintf('%s must be a non-empty session identifier of at most %d bytes.', $field, self::MAX_IDENTIFIER_BYTES));
        }

        return $value;
    }

    private static function invalid(string $message): MemoryIngestException
    {
        return new MemoryIngestException(MemoryIngestErrorCode::InvalidRequest, $message);
    }
}
