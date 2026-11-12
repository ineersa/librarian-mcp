<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ProjectHealth;
use Playwright\Assertions\AssertionOptions;
use Playwright\Assertions\Expect;
use Playwright\Page\PageInterface;

/**
 * Shared Playwright helpers for plan 03 project registration journeys.
 * Does not modify LibrarianNetworkBrowserTest; parent owns dashboard heading updates.
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

    protected function fillProjectName(string $name): void
    {
        $this->page->getByLabel('Name', ['exact' => true])->fill($name);
    }

    protected function chooseProjectDirectory(string $visibleAbsolutePath): void
    {
        $page = $this->page;
        $page->getByRole('button', ['name' => 'Choose directory', 'exact' => true])->click();
        $dialog = $page->getByRole('dialog');
        $dialog->waitFor();
        // Wait for the initial listing before editing its async-populated location.
        Expect::locator($dialog->getByRole('button', ['name' => 'Select this directory', 'exact' => true]))->toBeEnabled();
        $dialog->getByRole('textbox', ['name' => 'Directory location', 'exact' => true])->fill($visibleAbsolutePath);
        $dialog->getByRole('textbox', ['name' => 'Directory location', 'exact' => true])->press('Enter');
        Expect::locator($dialog->locator('[data-project-directory-target="currentPath"]'))->toHaveText($visibleAbsolutePath);
        $dialog->getByRole('button', ['name' => 'Select this directory', 'exact' => true])->click();
        Expect::locator($page->getByLabel('Project directory', ['exact' => true]))
            ->toHaveValue($visibleAbsolutePath, new AssertionOptions(timeoutMs: 10_000));
    }

    protected function submitRegisterOrEditForm(): void
    {
        $this->submitEasyAdminForm();
    }

    protected function registerProject(string $name, string $visibleDirectory): string
    {
        $this->openRegisterProjectForm();
        $this->fillProjectName($name);
        $this->chooseProjectDirectory($visibleDirectory);
        $this->submitRegisterOrEditForm();
        $this->page->waitForURL('**/admin/projects');
        $id = $this->page->locator('tr[data-project-id]')->filter(['hasText' => $name])->getAttribute('data-project-id');
        self::assertNotNull($id);

        $this->registeredProjectIds[$id] = true;

        return $id;
    }

    protected function openProjectDetail(string $projectId): PageInterface
    {
        $page = $this->visit('/admin/projects/'.$projectId);
        $page->locator('#project-source-status')->waitFor();

        return $page;
    }

    protected function triggerScanSources(): void
    {
        $this->page->getByRole('button', ['name' => 'Scan sources'])->click();
    }

    protected function waitForProjectHealth(ProjectHealth $health): void
    {
        Expect::locator($this->page->locator('[data-project-health]'))
            ->toHaveAttribute('data-project-health', $health->value, new AssertionOptions(timeoutMs: 60_000));
        if (ProjectHealth::Ready === $health) {
            Expect::locator($this->page->locator('#project-source-status'))
                ->toContainText('Sources discovered', new AssertionOptions(timeoutMs: 60_000));
        }
    }

    protected function assertSourceCount(string $label, int $expected): void
    {
        $value = $this->page->locator('.field-group:has(.field-label > :text-is("'.$label.'")) .field-value')->textContent();
        self::assertSame((string) $expected, trim($value));
    }
}
