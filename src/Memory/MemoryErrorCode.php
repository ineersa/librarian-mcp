<?php

declare(strict_types=1);

namespace App\Memory;

enum MemoryErrorCode: string
{
    case InvalidProjectContext = 'invalid_project_context';
    case LinkedWorktreeUnsupported = 'linked_worktree_unsupported';
    case ProjectNotFound = 'project_not_found';
    case ProjectRegistrationConflict = 'project_registration_conflict';
    case ProjectRegistrationUnavailable = 'project_registration_unavailable';
    case InvalidQuery = 'invalid_query';
    case InvalidLimit = 'invalid_limit';
    case InvalidAfter = 'invalid_after';
    case InvalidBefore = 'invalid_before';
    case InvalidDateRange = 'invalid_date_range';
    case InvalidId = 'invalid_id';
    case AmbiguousId = 'ambiguous_id';
    case NotFound = 'not_found';
    case SessionsUnavailable = 'sessions_unavailable';
    case EventsUnreadable = 'events_unreadable';
    case ResourceLimitExceeded = 'resource_limit_exceeded';
    case IndexUnavailable = 'index_unavailable';
    case EmbeddingFailed = 'embedding_failed';
    case RetrievalFailed = 'retrieval_failed';
    case SourceUnavailable = 'source_unavailable';
    case Timeout = 'timeout';
    case ResponseTooLarge = 'response_too_large';
    case InternalError = 'internal_error';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::IndexUnavailable, self::EmbeddingFailed, self::RetrievalFailed,
            self::SourceUnavailable, self::SessionsUnavailable, self::EventsUnreadable,
            self::Timeout, self::ProjectRegistrationUnavailable => true,
            default => false,
        };
    }
}
