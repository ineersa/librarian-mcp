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
        foreach (['async', 'om', 'om_reflect', 'cron'] as $transport) {
            $card = $stats->locator(\sprintf('.card:has(h3:text-is("%s"))', $transport));
            self::assertMatchesRegularExpression('/Queued:\s*\d+.*Delayed retries:\s*\d+/s', $card->innerText());
        }
        $failedCard = $stats->locator('.card:has(h3:text-is("failed"))');
        self::assertMatchesRegularExpression('/Total:\s*\d+/', $failedCard->innerText());
        self::assertTrue($page->getByRole('heading', ['name' => 'Failed messages', 'exact' => true])->isVisible());
        $page->getByText('Delayed retry details', ['exact' => true])->click();
        self::assertTrue($page->getByText('The live queue cards show the current counts.', ['exact' => false])->isVisible());
        $page->getByRole('link', ['name' => 'OM model'])->click();
        $page->getByRole('heading', ['name' => 'Observational memory model'])->waitFor();
        $model = $page->getByLabel('Model', ['exact' => true]);
        $reasoning = $page->getByLabel('Reasoning', ['exact' => true]);
        self::assertSame('llama_cpp/flash', $model->inputValue());
        $reasoning->waitFor();
        self::assertSame(8, $reasoning->locator('option')->count());
        $reasoning->selectOption('high');
        $page->getByRole('button', ['name' => 'Save OM model', 'exact' => true])->click();
        $page->getByText('Saved OM model llama_cpp/flash at High.', ['exact' => true])->waitFor();
        self::assertSame('high', $reasoning->inputValue());
        self::assertTrue($page->locator('dl')->getByText('200,000 tokens', ['exact' => true])->isVisible());
        // The vLLM preset exists only because .env.test sets a dummy VLLM_BASE_URL; it is never called.
        $model->selectOption('vllm/Qwen3.8-27B');
        $reasoning->locator('option[value="xhigh"]')->waitFor(['state' => 'attached']);
        self::assertSame(['low', 'medium', 'xhigh'], $reasoning->evaluate('el => Array.from(el.options, (option) => option.value)'));
        self::assertSame('low', $reasoning->inputValue());
        $reasoning->selectOption('medium');
        $page->getByRole('button', ['name' => 'Save OM model', 'exact' => true])->click();
        $page->getByText('Saved OM model vllm/Qwen3.8-27B at Medium.', ['exact' => true])->waitFor();
        self::assertSame('vllm/Qwen3.8-27B', $model->inputValue());
        self::assertSame('medium', $reasoning->inputValue());
        $model->selectOption('llama_cpp/flash');
        $reasoning->locator('option[value="high"]')->waitFor(['state' => 'attached']);
        $reasoning->selectOption('high');
        $page->getByRole('button', ['name' => 'Save OM model', 'exact' => true])->click();
        $page->getByText('Saved OM model llama_cpp/flash at High.', ['exact' => true])->waitFor();
        // Per-source OM token limits: the singleton opens on its edit page with the env defaults.
        $page->getByRole('link', ['name' => 'Settings'])->click();
        $claudeReflect = $page->getByLabel('Claude reflect after (tokens)', ['exact' => true]);
        $claudeReflect->waitFor();
        self::assertSame('40000', $claudeReflect->inputValue());
        self::assertSame('30000', $page->getByLabel('Claude observations max (tokens)', ['exact' => true])->inputValue());
        self::assertSame('40000', $page->getByLabel('Hatfield reflect after (tokens)', ['exact' => true])->inputValue());
        self::assertSame('30000', $page->getByLabel('Hatfield observations max (tokens)', ['exact' => true])->inputValue());
        $claudeReflect->fill('35000');
        $page->getByRole('button', ['name' => 'Save settings', 'exact' => true])->click();
        $page->getByText('Saved settings.', ['exact' => true])->waitFor();
        self::assertSame('35000', $page->getByLabel('Claude reflect after (tokens)', ['exact' => true])->inputValue());
        self::assertSame('40000', $page->getByLabel('Hatfield reflect after (tokens)', ['exact' => true])->inputValue());
        $page->getByRole('link', ['name' => 'Logs'])->click();
        $page->getByRole('heading', ['name' => 'Operations logs'])->waitFor();
        $source = $page->getByLabel('Source', ['exact' => true]);
        $source->selectOption('om-worker');
        $page->getByRole('button', ['name' => 'Filter', 'exact' => true])->click();
        $page->waitForURL('**/operations/logs?source=om-worker');
        self::assertSame('om-worker', $source->inputValue());
        $page->locator('.user-menu-wrapper:visible .user-details')->click();
        $page->getByRole('link', ['name' => 'Sign out', 'exact' => true])->click();
        $page->waitForURL('**/');
        self::assertTrue($page->getByRole('link', ['name' => 'Login', 'exact' => true])->isVisible());
    }
}
