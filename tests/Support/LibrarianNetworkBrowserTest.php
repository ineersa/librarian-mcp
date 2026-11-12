<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;
use Playwright\Browser\BrowserContextBuilder;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Browser\BrowserInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightClient;
use Playwright\PlaywrightFactory;

/** Real HTTP browser journeys; the runner owns the isolated stack and fixtures. */
abstract class LibrarianNetworkBrowserTest extends TestCase
{
    protected PageInterface $page;
    protected string $url;
    private ?PlaywrightClient $client = null;
    private ?BrowserInterface $browser = null;
    private ?BrowserContextInterface $context = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url = rtrim((string) getenv('LIBRARIAN_BROWSER_URL'), '/');
        self::assertMatchesRegularExpression('~^https?://.+~D', $this->url);
        $headed = 'false' === getenv('PLAYWRIGHT_HEADLESS');
        $this->client = PlaywrightFactory::create();
        try {
            $builder = $this->client->chromium()->withHeadless(!$headed)->withArgs([
                '--window-size=2560,1440',
            ]);
            if ($headed) {
                $builder->withSlowMo(1000);
            }
            $this->browser = $builder->launch();
            $this->context = $this->browser->newContext($headed ? ['viewport' => null] : BrowserContextBuilder::create()->withViewport(1920, 1080)->toArray());
            $this->page = $this->context->newPage();
        } catch (\Throwable $error) {
            $this->closeBrowser();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->closeBrowser();
        } finally {
            parent::tearDown();
        }
    }

    protected function visit(string $path): PageInterface
    {
        $this->page->goto($this->url.$path);

        return $this->page;
    }

    protected function login(string $email = 'admin@test.com', string $password = 'admin'): void
    {
        $page = $this->visit('/login');
        $page->getByPlaceholder('Email', ['exact' => true])->fill($email);
        $page->getByPlaceholder('Password', ['exact' => true])->fill($password);
        $page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->click();
        $page->waitForURL('**/admin**');
    }

    protected function logout(): void
    {
        $this->page->locator('.user-menu-wrapper:visible .user-details')->click();
        $this->page->getByRole('link', ['name' => 'Sign out', 'exact' => true])->click();
        $this->page->waitForURL('**/');
    }

    protected function assertPageContains(string $text): void
    {
        self::assertStringContainsString($text, $this->page->locator('body')->textContent());
    }

    protected function submitEasyAdminForm(): void
    {
        $this->page->locator('button[name="ea[newForm][btn]"], button[name="ea[editForm][btn]"]')->first()->click();
    }

    protected function deleteRecord(string $detailUrl): void
    {
        $this->page->goto($detailUrl);
        $this->page->locator('.action-delete')->click();
        $this->page->getByRole('button', ['name' => 'Confirm', 'exact' => true])->click();
        $this->page->locator('body.ea-index')->waitFor();
    }

    private function closeBrowser(): void
    {
        try {
            $this->context?->close();
        } finally {
            try {
                $this->browser?->close();
            } finally {
                $this->client?->close();
                $this->context = null;
                $this->browser = null;
                $this->client = null;
            }
        }
    }
}
