<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\ProjectHealth;
use App\Index\Source\MemoryKind;
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
        $observationId = ProjectSourceFixture::memoryId(MemoryKind::Observation, 1);
        $reflectionId = ProjectSourceFixture::memoryId(MemoryKind::Reflection, 1);

        try {
            $this->login();
            $page = $this->openProjectsIndex();
            self::assertTrue($page->getByRole('heading', ['name' => 'Projects', 'exact' => true])->isVisible());

            $projectId = $this->registerProject('Default OM Fixture', $visiblePath);
            $this->openProjectDetail($projectId);
            $this->waitForProjectHealth(ProjectHealth::Ready);
            $this->importLegacyOm($projectId, ProjectSourceFixture::defaultOmDatabasePath($slug));

            $this->openProjectsIndex();
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

            // Explicit legacy import queues indexing; discovery alone does not copy om.sqlite.
            $this->waitForCommittedIndex(3);
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Index status', new AssertionOptions(timeoutMs: 10_000));
            Expect::locator($this->page->locator('#project-index-status'))
                ->toContainText('Automatic indexing', new AssertionOptions(timeoutMs: 10_000));
            Expect::locator($this->page->locator('#project-index-status'))->toContainText('Active committed revision');
            $coverage = $this->page->locator('#observer-coverage');
            Expect::locator($coverage)->toContainText('Gap-free through');
            $coverage->getByRole('link', ['name' => '#', 'exact' => false])->click();
            Expect::locator($coverage)->toContainText('Back to latest sessions');

            $committedBefore = trim((string) $this->page->locator('[data-index-committed-revision-value]')->textContent());

            $this->openOmIndex($projectId);
            $sessionFilter = $this->page->getByLabel('Session ID', ['exact' => true]);
            $sessionFilter->fill('108');
            $this->page->getByRole('button', ['name' => 'Filter', 'exact' => true])->click();
            $clearFilter = $this->page->getByRole('link', ['name' => 'Clear filter', 'exact' => true]);
            $clearFilter->waitFor();
            Expect::locator($this->page->locator('[data-om-observation-id="'.$observationId.'"]'))->toContainText('medium');
            Expect::locator($this->page->locator('[data-om-observation-id="'.$observationId.'"]'))->toContainText('synthetic observation fx-1');
            Expect::locator($this->page->locator('[data-om-reflection-id="'.$reflectionId.'"]'))->toContainText('synthetic reflection fx-1');
            $this->page->locator('[data-om-observation-id="'.$observationId.'"]')->getByRole('link', ['name' => 'Recall'])->click();
            $this->page->locator('#project-memory-recall')->waitFor();
            Expect::locator($this->page->locator('[data-recall-kind]'))->toHaveText('observation');
            Expect::locator($this->page->locator('[data-recall-content]'))->toContainText('synthetic observation fx-1');
            Expect::locator($this->page->locator('[data-recall-evidence]'))->toContainText('synthetic sanitized event');
            $this->page->locator('[data-recall-back]')->click();
            $this->page->locator('#project-om-index')->waitFor();
            $this->page->locator('[data-om-reflection-id="'.$reflectionId.'"]')->getByRole('link', ['name' => 'Recall'])->click();
            $this->page->locator('#project-memory-recall')->waitFor();
            Expect::locator($this->page->locator('[data-recall-kind]'))->toHaveText('reflection');
            Expect::locator($this->page->locator('[data-recall-content]'))->toContainText('synthetic reflection fx-1');
            Expect::locator($this->page->locator('[data-recall-evidence]'))->toContainText('synthetic sanitized event');
            $this->page->locator('[data-recall-back]')->click();
            $clearFilter->waitFor();
            self::assertSame('108', $sessionFilter->inputValue());
            $clearFilter->click();
            $clearFilter->waitForDetached();
            self::assertSame('', $sessionFilter->inputValue());

            $this->openSearchMemory($projectId);
            $this->searchMemory('where was I working on synthetic observation fx-1');
            $this->assertSearchHitContains($observationId, 'synthetic observation fx-1');
            Expect::locator($this->page->locator('[data-search-hit][data-source-id="'.$observationId.'"]'))->toContainText('medium');
            $this->page->locator('[data-search-hit][data-source-id="'.$observationId.'"]')->getByRole('link', ['name' => 'Recall'])->click();
            $this->page->locator('#project-memory-recall')->waitFor();
            Expect::locator($this->page->locator('[data-recall-evidence]'))->toContainText('synthetic sanitized event');
            $this->page->locator('[data-recall-back]')->click();
            $this->page->locator('#project-memory-search')->waitFor();
            $this->assertSearchHitContains($observationId, 'synthetic observation fx-1');

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
            // Explicit import is one-time: later scans must not resurrect the deleted session.
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
            self::assertSame(0, $this->page->locator('[data-search-hit][data-source-id="'.$observationId.'"]')->count());

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
