<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;

final class AuthenticationTest extends LibrarianNetworkBrowserTest
{
    public function testLoginAndLogout(): void
    {
        $page = $this->visit('/login');
        $page->getByPlaceholder('Email', ['exact' => true])->fill('admin@test.com');
        $page->getByPlaceholder('Password', ['exact' => true])->fill('admin');
        $page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->click();
        $page->waitForURL('**/admin**');
        $page->getByRole('heading', ['name' => 'MCP Tools Dashboard', 'exact' => true])->waitFor();
        self::assertTrue($page->getByRole('heading', ['name' => 'MCP Tools Dashboard', 'exact' => true])->isVisible());
        $this->assertPageContains('Librarian MCP');
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $page->getByRole('link', ['name' => 'Sign out', 'exact' => true])->click();
        $page->waitForURL('**/');
        self::assertTrue($page->getByRole('link', ['name' => 'Login', 'exact' => true])->isVisible());
    }
}
