<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Actionable discovery health codes. Values are stable identifiers for UI/tests.
 */
enum ProjectSourceIssueCode: string
{
    case UserHomeUnavailable = 'user_home_unavailable';
    case RootUnavailable = 'root_unavailable';
    case HatfieldMissing = 'hatfield_missing';
    case SettingsMalformed = 'settings_malformed';
    case OmDisabled = 'om_disabled';
    case OmDatabaseMissing = 'om_database_missing';
    case OmDatabaseUnreadable = 'om_database_unreadable';
    case OmSchemaUnsupported = 'om_schema_unsupported';
    case OmSchemaUnknown = 'om_schema_unknown';
    case OmSchemaIncomplete = 'om_schema_incomplete';
    case SessionsUnavailable = 'sessions_unavailable';
    case JournalsUnreadable = 'journals_unreadable';
    case AppRootRequired = 'app_root_required';
    case UnexpectedFailure = 'unexpected_failure';
}
