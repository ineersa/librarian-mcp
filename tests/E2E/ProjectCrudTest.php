<?php

declare(strict_types=1);

namespace App\Tests\E2E;

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

        $this->login();
        $page = $this->openProjectsIndex();
        self::assertTrue($page->getByRole('heading', ['name' => 'Projects', 'exact' => true])->isVisible());

        $projectId = $this->registerProject('Default OM Fixture', $visiblePath);
        $this->openProjectDetail($projectId);
        $this->triggerScanSources();
        $this->waitForProjectHealth('Ready');

        $status = $this->page->locator('#project-source-status');
        Expect::locator($status)->toContainText('Sources discovered', new AssertionOptions(timeoutMs: 10_000));
        Expect::locator($status)->toContainText('.hatfield/extensions-data/observational-memory/om.sqlite');
        Expect::locator($status)->toContainText('.hatfield/sessions');
        Expect::locator($status)->toContainText('20261021_006_semantic_source_revisions');
        Expect::locator($status)->toContainText('2'); // observationCount
        Expect::locator($status)->toContainText('1'); // reflectionCount / sessionCount

        $this->deleteRecord('/admin/projects/'.$projectId);
        $this->openProjectsIndex();
        self::assertFalse($this->page->getByRole('link', ['name' => 'Default OM Fixture', 'exact' => true])->isVisible());

        $after = ProjectSourceFixture::hashTree($builderPath);
        ProjectSourceFixture::assertUnchanged($before, $after, 'default-om removal');
        self::assertDirectoryExists($builderPath);
        self::assertFileExists($builderPath.'/.hatfield/extensions-data/observational-memory/om.sqlite');
        self::assertFileExists($builderPath.'/.hatfield/sessions/108/events.jsonl');
    }
}
