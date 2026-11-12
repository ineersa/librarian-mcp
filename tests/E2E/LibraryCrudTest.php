<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;

final class LibraryCrudTest extends LibrarianNetworkBrowserTest
{
    public function testLibrariesIndexAndNewFormLoadWithoutSync(): void
    {
        $this->login();
        $page = $this->visit('/admin/libraries');
        self::assertTrue($page->getByRole('heading', ['name' => 'Libraries', 'exact' => true])->isVisible());
        $page->getByRole('link', ['name' => 'Add Library', 'exact' => true])->click();
        $page->waitForURL('**/admin/libraries/new**');
        self::assertTrue($page->getByRole('heading', ['name' => 'Add Library', 'exact' => true])->isVisible());
        self::assertTrue($page->getByLabel('Git URL', ['exact' => true])->isVisible());
        self::assertTrue($page->getByLabel('Branch', ['exact' => true])->isVisible());
        self::assertTrue($page->getByLabel('Description', ['exact' => true])->isVisible());
        self::assertTrue($page->locator('button[name="ea[newForm][btn]"]')->first()->isVisible());
    }
}
