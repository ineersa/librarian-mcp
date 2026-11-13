<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ProjectHealth;
use App\Tests\Support\LibrarianNetworkBrowserTest;
use App\Tests\Support\ProjectSourceBrowserHelpers;
use App\Tests\Support\ProjectSourceFixture;
use Playwright\Assertions\AssertionOptions;
use Playwright\Assertions\Expect;

final class ProjectCrudTest extends LibrarianNetworkBrowserTest
{
    use ProjectSourceBrowserHelpers;

    public function testRegisterDefaultProjectDiscoverSourcesAndRemoveRegistration(): void
    {
        $slug = 'default-om';
        $visiblePath = ProjectSourceFixture::visibleProjectPath($slug);
        $builderPath = ProjectSourceFixture::builderProjectPath($slug);
        $before = ProjectSourceFixture::hashTree($builderPath);
        $reboundPath = ProjectSourceFixture::builderProjectPath('custom-relative');
        $reboundBefore = ProjectSourceFixture::hashTree($reboundPath);
        $restoreFixture = null;

        try {
            $this->login();
            $page = $this->openProjectsIndex();
            self::assertTrue($page->getByRole('heading', ['name' => 'Projects', 'exact' => true])->isVisible());

            $projectId = $this->registerProject('Default OM Fixture', $visiblePath);
            $this->openProjectDetail($projectId);
            $this->triggerScanSources();
            $this->waitForProjectHealth(ProjectHealth::Ready);

            $status = $this->page->locator('#project-source-status');
            Expect::locator($status)->toContainText('Sources discovered', new AssertionOptions(timeoutMs: 10_000));
            $details = $this->page->locator('body');
            Expect::locator($details)->toContainText('.hatfield/extensions-data/observational-memory/om.sqlite');
            Expect::locator($details)->toContainText('.hatfield/sessions');
            Expect::locator($details)->toContainText('20261021_006_semantic_source_revisions');
            $this->assertSourceCount('Observations', 2);
            $this->assertSourceCount('Reflections', 1);
            $this->assertSourceCount('Sessions', 1);

            // Discovery success queues automatic indexing; Check for updates is optional.
            $this->triggerIndexNow();
            $this->waitForCommittedIndex(3);
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Index status', new AssertionOptions(timeoutMs: 10_000));
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Automatic indexing', new AssertionOptions(timeoutMs: 10_000));

            $this->openSearchMemory();
            $this->searchMemory('where was I working on synthetic observation fx-1');
            $this->assertSearchHitContains('fx-obs-1', 'synthetic observation fx-1');

            $updatedContent = 'synthetic observation fx-1 updated for indexing';
            $restoreFixture = ProjectSourceFixture::updateObservationContent(
                $builderPath,
                '.hatfield/extensions-data/observational-memory/om.sqlite',
                'fx-obs-1',
                $updatedContent,
                5,
            );
            $afterMutation = ProjectSourceFixture::hashTree($builderPath);
            self::assertNotSame($before, $afterMutation, 'Harness-owned fixture mutation must change the source hash.');

            $this->openProjectDetail($projectId);
            $this->triggerScanSources();
            $this->waitForProjectHealth(ProjectHealth::Ready);
            $this->triggerIndexNow();
            $this->waitForCommittedIndex(3);
            Expect::locator($this->page->locator('[data-index-committed-revision-value]'))
                ->toHaveText('5', new AssertionOptions(timeoutMs: 60_000));

            $this->openSearchMemory();
            $this->searchMemory($updatedContent);
            $this->assertSearchHitContains('fx-obs-1', $updatedContent);

            ProjectSourceFixture::assertUnchanged(
                $afterMutation,
                ProjectSourceFixture::hashTree($builderPath),
                'default-om after updated search',
            );

            // Explicit path rebinding and rename keep the same stable registration ID.
            $this->visit('/admin/projects/'.$projectId.'/edit');
            $this->fillProjectName('Rebound OM Fixture');
            $this->page->getByLabel('Project directory', ['exact' => true])->fill(ProjectSourceFixture::visibleProjectPath('custom-relative'));
            $this->submitRegisterOrEditForm();
            $this->page->waitForURL('**/admin/projects');
            $this->openProjectDetail($projectId);
            $this->waitForProjectHealth(ProjectHealth::Ready);
            Expect::locator($status)->toHaveAttribute('data-project-id', $projectId);
            Expect::locator($details)->toContainText('.hatfield/custom-om/memory.sqlite');
            Expect::locator($this->page->getByRole('heading', ['level' => 1]))->toContainText('Rebound OM Fixture');

            $this->deleteRecord('/admin/projects/'.$projectId);
            $this->openProjectsIndex();
            self::assertSame(0, $this->page->locator('tr[data-project-id="'.$projectId.'"]')->count());

            ProjectSourceFixture::assertUnchanged(
                $afterMutation,
                ProjectSourceFixture::hashTree($builderPath),
                'default-om removal',
            );
            ProjectSourceFixture::assertUnchanged(
                $reboundBefore,
                ProjectSourceFixture::hashTree($reboundPath),
                'rebound source removal',
            );
            self::assertDirectoryExists($builderPath);
            self::assertFileExists($builderPath.'/.hatfield/extensions-data/observational-memory/om.sqlite');
            self::assertFileExists($builderPath.'/.hatfield/sessions/108/events.jsonl');
        } finally {
            if (\is_callable($restoreFixture)) {
                $restoreFixture();
            }
            ProjectSourceFixture::assertUnchanged(
                $before,
                ProjectSourceFixture::hashTree($builderPath),
                'default-om restored after journey',
            );
        }
    }
}
