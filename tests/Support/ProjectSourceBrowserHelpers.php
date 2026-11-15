<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ProjectHealth;
use Playwright\Assertions\AssertionOptions;
use Playwright\Assertions\Expect;
use Playwright\Page\PageInterface;

/**
 * Shared Playwright helpers for project registration and indexing journeys.
 */
trait ProjectSourceBrowserHelpers
{
    /** @var array<int|string, true> */
    private array $registeredProjectIds = [];

    protected function tearDown(): void
    {
        try {
            foreach (array_keys($this->registeredProjectIds) as $id) {
                $this->deleteRecord('/admin/projects/'.$id);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function deleteRecord(string $detailUrl): void
    {
        parent::deleteRecord($this->url.$detailUrl);
        unset($this->registeredProjectIds[basename($detailUrl)]);
    }

    protected function openProjectsIndex(): PageInterface
    {
        $page = $this->visit('/admin/projects');
        $page->getByRole('heading', ['name' => 'Projects', 'exact' => true])->waitFor();

        return $page;
    }

    protected function openRegisterProjectForm(): PageInterface
    {
        $page = $this->openProjectsIndex();
        $page->getByRole('button', ['name' => 'Register Project'])->click();
        $page->waitForURL('**/admin/projects/new**');
        $page->getByRole('heading', ['name' => 'Register Project'])->waitFor();

        return $page;
    }

    protected function registerProject(string $name, string $visibleDirectory): string
    {
        $this->openRegisterProjectForm();
        $this->page->getByLabel('Name', ['exact' => true])->fill($name);
        $this->page->getByLabel('Project directory', ['exact' => true])->fill($visibleDirectory);
        $this->submitEasyAdminForm();
        $this->page->waitForURL('**/admin/projects');
        $id = $this->page->locator('tr[data-project-id]')->filter(['hasText' => $name])->getAttribute('data-project-id');
        self::assertNotNull($id);

        $this->registeredProjectIds[$id] = true;

        return $id;
    }

    protected function openProjectDetail(string $projectId): PageInterface
    {
        $page = $this->openProjectsIndex();
        $page->locator('tr[data-project-id="'.$projectId.'"] td[data-column="name"]')->click();
        $page->waitForURL('**/admin/projects/'.$projectId.'**');
        $page->locator('#project-source-status')->waitFor();

        return $page;
    }

    protected function waitForProjectHealth(ProjectHealth $health): void
    {
        Expect::locator($this->page->locator('#project-source-status'))
            ->toHaveAttribute('data-project-health', $health->value, new AssertionOptions(timeoutMs: 60_000));
        if (ProjectHealth::Ready === $health) {
            Expect::locator($this->page->locator('#project-source-status'))
                ->toContainText('Sources discovered', new AssertionOptions(timeoutMs: 60_000));
        }
    }

    protected function waitForCommittedIndex(int $minParentCount = 1, int $timeoutMs = 180_000): void
    {
        Expect::locator($this->page->locator('[data-index-generation-id]'))
            ->toBeVisible(new AssertionOptions(timeoutMs: $timeoutMs));
        Expect::locator($this->page->locator('#project-index-status'))
            ->toHaveAttribute('data-index-pending', '0', new AssertionOptions(timeoutMs: $timeoutMs));
        Expect::locator($this->page->locator('#project-index-status [data-index-status-label]'))
            ->toContainText('Indexed', new AssertionOptions(timeoutMs: $timeoutMs));
        Expect::locator($this->page->locator('[data-index-parent-count-value]'))
            ->not()->toHaveText('0', new AssertionOptions(timeoutMs: $timeoutMs));
        $parentCount = trim((string) $this->page->locator('[data-index-parent-count-value]')->textContent());
        self::assertGreaterThanOrEqual($minParentCount, (int) $parentCount);
        $committed = trim((string) $this->page->locator('[data-index-committed-revision-value]')->textContent());
        self::assertNotSame('Not committed', $committed);
        self::assertNotSame('', $committed);
        Expect::locator($this->page->locator('[data-index-last-success]'))
            ->not()->toHaveText('Never indexed', new AssertionOptions(timeoutMs: 10_000));
    }

    protected function openSearchMemory(string $projectId): PageInterface
    {
        $this->openProjectsIndex()->locator('tr[data-project-id="'.$projectId.'"]')
            ->getByRole('button', ['name' => 'Search memory'])->click();
        $this->page->waitForURL('**/search-memory**');
        $this->page->locator('#project-memory-search')->waitFor();

        return $this->page;
    }

    protected function searchMemory(string $question): void
    {
        $this->page->getByLabel('Question', ['exact' => true])->fill($question);
        $this->page->getByRole('button', ['name' => 'Search', 'exact' => true])->click();
        $this->page->locator('#project-memory-search')->waitFor();
    }

    protected function assertSearchHitContains(string $sourceId, string $snippetFragment): void
    {
        $hit = $this->page->locator('[data-search-hit][data-source-id="'.$sourceId.'"]');
        Expect::locator($hit)->toBeVisible(new AssertionOptions(timeoutMs: 30_000));
        Expect::locator($hit)->toContainText($snippetFragment, new AssertionOptions(timeoutMs: 10_000));
    }

    protected function assertSourceCount(string $label, int $expected): void
    {
        $selector = match ($label) {
            'Observations' => '[data-source-count="observations"]',
            'Reflections' => '[data-source-count="reflections"]',
            'Sessions' => '[data-source-count="sessions"]',
            default => throw new \InvalidArgumentException(\sprintf('Unsupported live source count label "%s".', $label)),
        };
        $locator = $this->page->locator('#project-source-status '.$selector);
        Expect::locator($locator)->toHaveText((string) $expected, new AssertionOptions(timeoutMs: 60_000));
        $value = $locator->textContent();
        self::assertSame((string) $expected, trim((string) $value));
    }
}
