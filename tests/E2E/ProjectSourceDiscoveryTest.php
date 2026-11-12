<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ProjectHealth;
use App\Tests\Support\LibrarianNetworkBrowserTest;
use App\Tests\Support\ProjectSourceBrowserHelpers;
use App\Tests\Support\ProjectSourceFixture;
use Playwright\Assertions\AssertionOptions;
use Playwright\Assertions\Expect;

/**
 * Plan 03 authorized special-case source discovery journeys.
 * Each method is an explicitly named fixture case (no data providers).
 */
final class ProjectSourceDiscoveryTest extends LibrarianNetworkBrowserTest
{
    use ProjectSourceBrowserHelpers;

    public function testCustomAbsolutePathMappedLocationsAreDiscovered(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Custom Absolute Paths',
            ProjectSourceFixture::visibleProjectPath('custom-absolute'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Ready);

        $status = $this->page->locator('#project-source-status');
        Expect::locator($status)->toContainText('/synthetic-home/absolute-om/custom-absolute.sqlite');
        Expect::locator($status)->toContainText('/synthetic-home/absolute-sessions/custom-absolute');
        Expect::locator($status)->toContainText('Sources discovered');
        Expect::locator($status)->toContainText('4');
    }

    public function testMissingHatfieldDirectoryReportsDistinctError(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Missing Hatfield',
            ProjectSourceFixture::visibleProjectPath('missing-hatfield'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Error);
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('.hatfield', new AssertionOptions(timeoutMs: 10_000));
    }

    public function testMissingOmDatabaseReportsDistinctError(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Missing OM Database',
            ProjectSourceFixture::visibleProjectPath('missing-om-db'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Error);
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('database', new AssertionOptions(ignoreCase: true, timeoutMs: 10_000));
    }

    public function testInactiveObservationalMemoryExtensionReportsDistinctError(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Inactive OM Extension',
            ProjectSourceFixture::visibleProjectPath('inactive-extension'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Error);
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('ObservationalMemoryExtension', new AssertionOptions(timeoutMs: 10_000));
    }

    public function testMalformedSettingsYamlReportsDistinctError(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Malformed Settings',
            ProjectSourceFixture::visibleProjectPath('malformed-settings'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Error);
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('settings', new AssertionOptions(ignoreCase: true, timeoutMs: 10_000));
    }

    public function testUnsupportedOmSchemaReportsDistinctError(): void
    {
        $builderPath = ProjectSourceFixture::builderProjectPath('unsupported-schema');
        $before = ProjectSourceFixture::hashTree($builderPath);
        $this->login();
        $projectId = $this->registerProject(
            'Unsupported Schema',
            ProjectSourceFixture::visibleProjectPath('unsupported-schema'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Error);
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('schema', new AssertionOptions(ignoreCase: true, timeoutMs: 10_000));
        ProjectSourceFixture::assertUnchanged($before, ProjectSourceFixture::hashTree($builderPath), 'unsupported-schema failed discovery');
    }

    public function testUnreadableSessionJournalReportsDistinctError(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Unreadable Journal',
            ProjectSourceFixture::visibleProjectPath('unreadable-journal'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Error);
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('journal', new AssertionOptions(ignoreCase: true, timeoutMs: 10_000));
    }

    public function testUnavailableConfiguredMountReportsDistinctError(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Unavailable Mount',
            ProjectSourceFixture::visibleProjectPath('unavailable-mount'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Error);
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('mount', new AssertionOptions(ignoreCase: true, timeoutMs: 10_000));
    }

    public function testSymlinkedOmDatabaseResolvesReadOnlySourceMetadata(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Symlink Resolved OM',
            ProjectSourceFixture::visibleProjectPath('symlink-resolved'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Ready);

        $status = $this->page->locator('#project-source-status');
        Expect::locator($status)->toContainText('Sources discovered');
        Expect::locator($status)->toContainText('2');
        Expect::locator($status)->toContainText('1');
        // Relaxed policy: resolved symlink metadata is accepted, not a forbidden-root error.
        Expect::locator($this->page->locator('[data-project-health]'))
            ->toHaveAttribute('data-project-health', ProjectHealth::Ready->value);
    }

    public function testDistinctProjectsWithSameSessionIdRemainIsolated(): void
    {
        $this->login();

        $alphaId = $this->registerProject(
            'Isolation Alpha',
            ProjectSourceFixture::visibleProjectPath('isolation-alpha'),
        );
        $this->openProjectDetail($alphaId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Ready);
        $this->assertSourceCount('Observations', 2);
        $this->assertSourceCount('Reflections', 1);
        $this->assertSourceCount('Sessions', 1);

        $betaId = $this->registerProject(
            'Isolation Beta',
            ProjectSourceFixture::visibleProjectPath('isolation-beta'),
        );
        $this->openProjectDetail($betaId);
        $this->triggerScanSources();
        $this->waitForProjectHealth(ProjectHealth::Ready);
        $this->assertSourceCount('Observations', 3);
        $this->assertSourceCount('Reflections', 2);
        $this->assertSourceCount('Sessions', 1);

        self::assertNotSame($alphaId, $betaId);
        // Same numeric session id 108 exists in both fixtures; metadata stays project-scoped.
        $this->openProjectDetail($alphaId);
        $this->assertSourceCount('Observations', 2);
        $this->assertSourceCount('Reflections', 1);
        Expect::locator($this->page->locator('#project-source-status'))->not()->toContainText('isolation-beta');
    }
}
