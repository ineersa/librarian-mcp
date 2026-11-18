<?php

declare(strict_types=1);

namespace App\Project;

/**
 * Hatfield observational-memory activation identifiers.
 */
final class OmExtensionIds
{
    public const string CLASS_NAME = 'Ineersa\\HatfieldExt\\ObservationalMemory\\ObservationalMemoryExtension';

    /**
     * Exact applied OM schema versions required for discovery support.
     *
     * @var list<string>
     */
    public const array REQUIRED_SCHEMA_VERSIONS = [
        '20260722_001_domain',
        '20260725_002_reflection_multi_and_indexes',
        '20260726_003_active_generation_and_relevance_text',
        '20260729_004_current_activity',
        '20260922_005_semantic_health',
        '20261021_006_semantic_source_revisions',
    ];

    public const string LATEST_SCHEMA_VERSION = '20261021_006_semantic_source_revisions';

    /**
     * Adds only om_source_revision/om_source_change/om_semantic_progress; observation, reflection,
     * coverage and generation tables are identical without it, so a database that stopped at the
     * previous version (Hatfield no longer migrates OM) is still importable, without revision metadata.
     */
    public const string SOURCE_REVISIONS_SCHEMA_VERSION = '20261021_006_semantic_source_revisions';
}
