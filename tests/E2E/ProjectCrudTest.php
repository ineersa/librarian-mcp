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
        $restoreFixture = null;

        try {
            $this->login();
            $page = $this->openProjectsIndex();
            self::assertTrue($page->getByRole('heading', ['name' => 'Projects', 'exact' => true])->isVisible());

            $projectId = $this->registerProject('Default OM Fixture', $visiblePath);
            $this->openProjectDetail($projectId);
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

            // Registration starts discovery and indexing automatically.
            $this->waitForCommittedIndex(3);
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Index status', new AssertionOptions(timeoutMs: 10_000));
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Automatic indexing', new AssertionOptions(timeoutMs: 10_000));

            $this->openProjectsIndex();
            $indexCell = $this->page->locator('#project-index-list-'.$projectId);
            Expect::locator($indexCell)->toContainText('Indexed');
            Expect::locator($indexCell)->toContainText('3 indexed memories');
            $this->openSearchMemory($projectId);
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

            // The scheduler must discover and index the update without either manual action.
            $this->openProjectDetail($projectId);
            Expect::locator($this->page->locator('[data-index-committed-revision-value]'))
                ->toHaveText('5', new AssertionOptions(timeoutMs: 60_000));
            Expect::locator($this->page->locator('[data-index-source-revision]'))->toHaveText('5');
            $this->waitForProjectHealth(ProjectHealth::Ready);
            $this->waitForCommittedIndex(3);

            $this->openSearchMemory($projectId);
            $this->searchMemory($updatedContent);
            $this->assertSearchHitContains('fx-obs-1', $updatedContent);

            ProjectSourceFixture::assertUnchanged(
                $afterMutation,
                ProjectSourceFixture::hashTree($builderPath),
                'default-om after updated search',
            );

            $this->deleteRecord('/admin/projects/'.$projectId);
            $this->openProjectsIndex();
            self::assertSame(0, $this->page->locator('tr[data-project-id="'.$projectId.'"]')->count());

            ProjectSourceFixture::assertUnchanged(
                $afterMutation,
                ProjectSourceFixture::hashTree($builderPath),
                'default-om removal',
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
