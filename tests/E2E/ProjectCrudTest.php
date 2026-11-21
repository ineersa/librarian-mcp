<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ProjectHealth;
use App\Mcp\MemoryContextResolver;
use App\Tests\Support\LibrarianNetworkBrowserTest;
use App\Tests\Support\ProjectSourceBrowserHelpers;
use App\Tests\Support\ProjectSourceFixture;
use Playwright\Assertions\AssertionOptions;
use Playwright\Assertions\Expect;

final class ProjectCrudTest extends LibrarianNetworkBrowserTest
{
    use ProjectSourceBrowserHelpers;

    public function testRegisterDefaultProjectDiscoverSourcesDeleteSessionAndRemoveRegistration(): void
    {
        $slug = 'default-om';
        $visiblePath = ProjectSourceFixture::visibleProjectPath($slug);
        $builderPath = ProjectSourceFixture::builderProjectPath($slug);
        $before = ProjectSourceFixture::hashTree($builderPath);

        try {
            $this->login();
            $page = $this->openProjectsIndex();
            self::assertTrue($page->getByRole('heading', ['name' => 'Projects', 'exact' => true])->isVisible());

            $projectId = $this->registerProject('Default OM Fixture', $visiblePath);
            $indexCell = $this->page->locator('#project-index-list-'.$projectId);
            Expect::locator($indexCell->locator('[data-source-count="observations"]'))
                ->toHaveText('2', new AssertionOptions(timeoutMs: 60_000));
            Expect::locator($indexCell->locator('[data-source-count="reflections"]'))->toHaveText('1');
            Expect::locator($indexCell->locator('[data-index-status-label]'))
                ->toHaveText('Indexed', new AssertionOptions(timeoutMs: 180_000));
            Expect::locator($indexCell)->toContainText('3 indexed memories');
            $this->openProjectDetail($projectId);
            $this->waitForProjectHealth(ProjectHealth::Ready);

            $status = $this->page->locator('#project-source-status');
            Expect::locator($status)->toContainText('Sources discovered', new AssertionOptions(timeoutMs: 10_000));
            $details = $this->page->locator('body');
            Expect::locator($details)->toContainText('.hatfield/extensions-data/observational-memory/om.sqlite');
            Expect::locator($details)->toContainText('.hatfield/sessions');
            $this->assertSourceCount('Observations', 2);
            $this->assertSourceCount('Reflections', 1);
            $this->assertSourceCount('Sessions', 1);

            // Registration starts discovery and indexing automatically.
            $this->waitForCommittedIndex(3);
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Index status', new AssertionOptions(timeoutMs: 10_000));
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Automatic indexing', new AssertionOptions(timeoutMs: 10_000));

            $committedBefore = trim((string) $this->page->locator('[data-index-committed-revision-value]')->textContent());

            $this->openSearchMemory($projectId);
            $this->searchMemory('where was I working on synthetic observation fx-1');
            $this->assertSearchHitContains('fx-obs-1', 'synthetic observation fx-1');

            // Post-cutover change path: the client deletes a session and Librarian forgets
            // its memory. The scheduler indexes the removal without manual actions.
            $token = $this->regenerateMcpToken();
            $this->openProjectsIndex();
            $listTimeOrigin = $this->page->evaluate('performance.timeOrigin');
            // A supplied token is always validated, so it works in required and anonymous mode.
            $headers = [MemoryContextResolver::PROJECT_HEADER => $visiblePath, 'Authorization' => 'Bearer '.$token];
            $response = $this->stackHttpClient()->request('DELETE', $this->url.'/api/om/v1/sessions/hatfield/108', ['headers' => $headers]);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['ok' => true, 'contract_version' => 1, 'deleted' => true], $response->toArray());

            Expect::locator($indexCell)->toHaveAttribute('data-index-parent-count', '0', new AssertionOptions(timeoutMs: 60_000));
            Expect::locator($indexCell->locator('[data-index-summary-label]'))->toHaveText('0 indexed memories');
            Expect::locator($indexCell->locator('[data-source-count="observations"]'))->toHaveText('0');
            Expect::locator($indexCell->locator('[data-source-count="reflections"]'))->toHaveText('0');
            // The legacy om.sqlite import is one-time: two later scans (serial on one worker)
            // must not resurrect the deleted session from the unchanged source file.
            for ($scan = 0; $scan < 2; ++$scan) {
                $lastCheck = trim((string) $indexCell->locator('[data-source-last-check]')->textContent());
                Expect::locator($indexCell->locator('[data-source-last-check]'))
                    ->not()->toHaveText($lastCheck, new AssertionOptions(timeoutMs: 60_000));
            }
            Expect::locator($indexCell)->toHaveAttribute('data-index-pending', '0', new AssertionOptions(timeoutMs: 60_000));
            Expect::locator($indexCell)->toHaveAttribute('data-index-parent-count', '0');
            self::assertSame($listTimeOrigin, $this->page->evaluate('performance.timeOrigin'), 'Live list updates must not reload the page.');

            $this->openProjectDetail($projectId);
            $this->waitForProjectHealth(ProjectHealth::Ready);
            // The index tracks Librarian's memory revision, which the deletion advanced.
            Expect::locator($this->page->locator('[data-index-committed-revision-value]'))
                ->not()->toHaveText($committedBefore, new AssertionOptions(timeoutMs: 60_000));
            Expect::locator($this->page->locator('[data-index-committed-revision-value]'))
                ->toHaveText(trim((string) $this->page->locator('[data-index-memory-revision]')->textContent()));

            $this->openSearchMemory($projectId);
            $this->searchMemory('where was I working on synthetic observation fx-1');
            Expect::locator($this->page->locator('[data-search-empty], [data-search-no-active]'))->toBeVisible(new AssertionOptions(timeoutMs: 30_000));
            self::assertSame(0, $this->page->locator('[data-search-hit][data-source-id="fx-obs-1"]')->count());

            ProjectSourceFixture::assertUnchanged($before, ProjectSourceFixture::hashTree($builderPath), 'default-om after session deletion');

            $this->deleteRecord('/admin/projects/'.$projectId);
            $this->openProjectsIndex();
            self::assertSame(0, $this->page->locator('tr[data-project-id="'.$projectId.'"]')->count());

            ProjectSourceFixture::assertUnchanged($before, ProjectSourceFixture::hashTree($builderPath), 'default-om removal');
            self::assertDirectoryExists($builderPath);
            self::assertFileExists($builderPath.'/.hatfield/extensions-data/observational-memory/om.sqlite');
            self::assertFileExists($builderPath.'/.hatfield/sessions/108/events.jsonl');
        } finally {
            ProjectSourceFixture::assertUnchanged(
                $before,
                ProjectSourceFixture::hashTree($builderPath),
                'default-om restored after journey',
            );
        }
    }
}
