<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;
use App\Tests\Support\ProjectSourceBrowserHelpers;
use App\Tests\Support\ProjectSourceFixture;

/**
 * Plan 03 form-validation journeys for unique name and Git origin rules.
 */
final class ProjectSourceValidationTest extends LibrarianNetworkBrowserTest
{
    use ProjectSourceBrowserHelpers;

    public function testDuplicateProjectNameIsRejected(): void
    {
        $this->login();
        $this->registerProject(
            'Unique Name Case',
            ProjectSourceFixture::visibleProjectPath('default-om'),
        );

        $this->openRegisterProjectForm();
        $this->fillProjectName('unique name case'); // case-insensitive / trimmed uniqueness
        $this->chooseProjectDirectory(ProjectSourceFixture::visibleProjectPath('duplicate-name-source'));
        $this->submitRegisterOrEditForm();
        $this->assertFormValidationVisible();
        self::assertStringContainsString('admin/projects/new', $this->page->url());
    }

    public function testDuplicateGitOriginAcrossCheckoutsIsRejected(): void
    {
        $this->login();
        $this->registerProject(
            'Origin Alpha',
            ProjectSourceFixture::visibleProjectPath('default-om'),
        );

        $this->openRegisterProjectForm();
        $this->fillProjectName('Origin Beta Different Name');
        $this->chooseProjectDirectory(ProjectSourceFixture::visibleProjectPath('duplicate-origin-source'));
        $this->submitRegisterOrEditForm();
        $this->assertFormValidationVisible();
        self::assertStringContainsString('admin/projects/new', $this->page->url());
    }

    public function testDuplicateCanonicalDirectoryIsRejected(): void
    {
        $this->login();
        $path = ProjectSourceFixture::visibleProjectPath('custom-relative');
        $this->registerProject('Canonical Directory One', $path);

        $this->openRegisterProjectForm();
        $this->fillProjectName('Canonical Directory Two');
        $this->chooseProjectDirectory($path);
        $this->submitRegisterOrEditForm();
        $this->assertFormValidationVisible();
        self::assertStringContainsString('admin/projects/new', $this->page->url());
    }
}
