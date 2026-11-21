<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Tests\Support\LibrarianNetworkBrowserTest;

final class AuthenticationTest extends LibrarianNetworkBrowserTest
{
    public function testLoginAndLogout(): void
    {
        $page = $this->visit('/login');
        $page->getByLabel('Email', ['exact' => true])->fill('admin@test.com');
        $page->getByLabel('Password', ['exact' => true])->fill('admin');
        $page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->click();
        $page->waitForURL('**/admin**');
        $page->getByRole('heading', ['name' => 'Projects dashboard', 'exact' => true])->waitFor();
        self::assertTrue($page->getByRole('heading', ['name' => 'Projects dashboard', 'exact' => true])->isVisible());
        $this->assertPageContains('Librarian MCP');
        $stats = $page->locator('#messenger-stats');
        foreach (['async', 'om', 'cron'] as $transport) {
            $card = $stats->locator(\sprintf('.card:has(h3:text-is("%s"))', $transport));
            self::assertMatchesRegularExpression('/Queued:\s*\d+.*Delayed retries:\s*\d+/s', $card->innerText());
        }
        $failedCard = $stats->locator('.card:has(h3:text-is("failed"))');
        self::assertMatchesRegularExpression('/Total:\s*\d+/', $failedCard->innerText());
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $page->getByRole('link', ['name' => 'Sign out', 'exact' => true])->click();
        $page->waitForURL('**/');
        self::assertTrue($page->getByRole('link', ['name' => 'Login', 'exact' => true])->isVisible());
    }
}
