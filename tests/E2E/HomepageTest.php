<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;

final class HomepageTest extends LibrarianNetworkBrowserTest
{
    public function testLandingLinksToLogin(): void
    {
        $page = $this->visit('/');
        self::assertSame('Librarian MCP', trim($page->getByRole('heading', ['level' => 1])->textContent()));
        $this->assertPageContains('Shared memory service for local Hatfield projects');
        $page->getByRole('link', ['name' => 'Login', 'exact' => true])->click();
        $page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->waitFor();
        self::assertSame('Librarian MCP', trim($page->locator('#header-logo .logo')->textContent()));
        self::assertTrue($page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->isVisible());
    }
}
