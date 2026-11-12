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
        $this->assertPageContains('System for libraries and documentation management, ingestion pipeline and MCP server');
        $page->getByRole('link', ['name' => 'Login', 'exact' => true])->click();
        $page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->waitFor();
        $heading = $page->getByRole('heading', ['level' => 1]);
        $heading->waitFor();
        self::assertSame('Librarian MCP', trim($heading->textContent()));
        self::assertTrue($page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->isVisible());
    }
}
