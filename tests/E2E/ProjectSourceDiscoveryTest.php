<?php

declare(strict_types=1);

namespace App\Tests\E2E;

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

    public function testCustomRelativeDatabaseAndSessionPathsAreDiscovered(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Custom Relative Paths',
            ProjectSourceFixture::visibleProjectPath('custom-relative'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth('Ready');

        $status = $this->page->locator('#project-source-status');
        Expect::locator($status)->toContainText('.hatfield/custom-om/memory.sqlite');
        Expect::locator($status)->toContainText('.hatfield/custom-sessions');
        Expect::locator($status)->toContainText('Sources discovered');
    }

    public function testCustomAbsolutePathMappedLocationsAreDiscovered(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Custom Absolute Paths',
            ProjectSourceFixture::visibleProjectPath('custom-absolute'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth('Ready');

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
        $this->waitForProjectHealth('Error');
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
        $this->waitForProjectHealth('Error');
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
        $this->waitForProjectHealth('Error');
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
        $this->waitForProjectHealth('Error');
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('settings', new AssertionOptions(ignoreCase: true, timeoutMs: 10_000));
    }

    public function testUnsupportedOmSchemaReportsDistinctError(): void
    {
        $this->login();
        $projectId = $this->registerProject(
            'Unsupported Schema',
            ProjectSourceFixture::visibleProjectPath('unsupported-schema'),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth('Error');
        Expect::locator($this->page->locator('#project-source-status'))
            ->toContainText('schema', new AssertionOptions(ignoreCase: true, timeoutMs: 10_000));
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
        $this->waitForProjectHealth('Error');
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
        $this->waitForProjectHealth('Error');
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
        $this->waitForProjectHealth('Ready');

        $status = $this->page->locator('#project-source-status');
        Expect::locator($status)->toContainText('Sources discovered');
        Expect::locator($status)->toContainText('2');
        Expect::locator($status)->toContainText('1');
        // Relaxed policy: resolved symlink metadata is accepted, not a forbidden-root error.
        Expect::locator($this->page->locator('[data-project-health]'))
            ->toHaveAttribute('data-project-health', 'Ready');
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
        $this->waitForProjectHealth('Ready');
        $alphaStatus = $this->page->locator('#project-source-status')->textContent() ?? '';
        self::assertStringContainsString('2', $alphaStatus);
        self::assertStringContainsString('1', $alphaStatus);

        $betaId = $this->registerProject(
            'Isolation Beta',
            ProjectSourceFixture::visibleProjectPath('isolation-beta'),
        );
        $this->openProjectDetail($betaId);
        $this->triggerScanSources();
        $this->waitForProjectHealth('Ready');
        $betaStatus = $this->page->locator('#project-source-status')->textContent() ?? '';
        self::assertStringContainsString('3', $betaStatus);
        self::assertStringContainsString('2', $betaStatus);

        self::assertNotSame($alphaId, $betaId);
        // Same numeric session id 108 exists in both fixtures; metadata stays project-scoped.
        $this->openProjectDetail($alphaId);
        Expect::locator($this->page->locator('#project-source-status'))->toContainText('2');
        Expect::locator($this->page->locator('#project-source-status'))->not()->toContainText('isolation-beta');
    }

    public function testFailedDiscoveryLeavesSourceFixtureUnchanged(): void
    {
        $slug = 'scan-failure';
        $builderPath = ProjectSourceFixture::builderProjectPath($slug);
        $before = ProjectSourceFixture::hashTree($builderPath);

        $this->login();
        $projectId = $this->registerProject(
            'Scan Failure Proof',
            ProjectSourceFixture::visibleProjectPath($slug),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth('Error');

        $after = ProjectSourceFixture::hashTree($builderPath);
        ProjectSourceFixture::assertUnchanged($before, $after, 'scan-failure discovery');
        self::assertFileExists($builderPath.'/PROOF.txt');
        self::assertSame("scan-failure-marker\n", (string) file_get_contents($builderPath.'/PROOF.txt'));
    }

    public function testRegistrationRemovalLeavesSourceFixtureUnchanged(): void
    {
        $slug = 'removal-proof';
        $builderPath = ProjectSourceFixture::builderProjectPath($slug);
        $before = ProjectSourceFixture::hashTree($builderPath);

        $this->login();
        $projectId = $this->registerProject(
            'Removal Proof',
            ProjectSourceFixture::visibleProjectPath($slug),
        );
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth('Ready');
        $this->deleteRecord('/admin/projects/'.$projectId);

        $after = ProjectSourceFixture::hashTree($builderPath);
        ProjectSourceFixture::assertUnchanged($before, $after, 'removal-proof');
        self::assertDirectoryExists($builderPath);
        self::assertFileExists($builderPath.'/.hatfield/extensions-data/observational-memory/om.sqlite');
        self::assertFileExists($builderPath.'/.hatfield/sessions/5/events.jsonl');
        self::assertSame("removal-proof-marker\n", (string) file_get_contents($builderPath.'/PROOF.txt'));
    }
}
