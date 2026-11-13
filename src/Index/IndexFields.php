<?php

declare(strict_types=1);

namespace App\Index;

/**
 * Named metadata keys stored with every indexed chunk.
 */
final class IndexFields
{
    public const string PROJECT_ID = 'project_id';
    public const string GENERATION_ID = 'generation_id';
    public const string KIND = 'kind';
    public const string SOURCE_ID = 'source_id';
    public const string SESSION_ID = 'session_id';
    /** UTC epoch seconds for native numeric filters. */
    public const string OCCURRED_AT = 'occurred_at';
    public const string CONTENT_HASH = 'content_hash';
    public const string CHUNK_POSITION = 'chunk_position';

    private function __construct()
    {
    }
}
