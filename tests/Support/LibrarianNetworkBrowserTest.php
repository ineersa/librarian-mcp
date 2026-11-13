<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use Hautelook\AliceBundle\PhpUnit\RefreshDatabaseState;
use Hautelook\AliceBundle\PhpUnit\RefreshDatabaseTrait;
use Playwright\Browser\BrowserContextBuilder;
use Playwright\Browser\BrowserContextInterface;
use Playwright\Browser\BrowserInterface;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightClient;
use Playwright\PlaywrightFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Real HTTP journeys: fixtures must be committed for the separate web/worker processes. */
#[SkipDatabaseRollback]
abstract class LibrarianNetworkBrowserTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    protected PageInterface $page;
    protected string $url;
    private ?PlaywrightClient $client = null;
    private ?BrowserInterface $browser = null;
    private ?BrowserContextInterface $context = null;

    protected function setUp(): void
    {
        parent::setUp();
        $root = \dirname(__DIR__, 2);
        $databaseUrl = (string) getenv('DATABASE_URL');
        if ('test' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV'))) {
            throw new \LogicException('Browser fixtures require the test environment.');
        }
        $this->assertOwnedBrowserDatabaseUrl($databaseUrl);
        foreach ([$root.'/var', $root.'/var/browser-live'] as $path) {
            if (is_link($path)) {
                throw new \LogicException('Refusing symlinked browser fixture storage.');
            }
        }
        if ('1' === getenv('LIBRARIAN_BROWSER_REUSE_STORAGE')) {
            // A live diagnostic must not let Alice purge an already prepared stack.
            RefreshDatabaseState::setDbPopulated(true);
        }
        self::bootKernel();
        // The trait opens a transaction, loading Alice fixtures only for fresh runs.
        // Commit it before real HTTP so fixture data is visible outside PHPUnit.
        self::getContainer()->get('doctrine')->getConnection()->commit();
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
        $page->getByLabel('Email', ['exact' => true])->fill($email);
        $page->getByLabel('Password', ['exact' => true])->fill($password);
        $page->getByRole('button', ['name' => 'Sign in', 'exact' => true])->click();
        $page->waitForURL('**/admin**');
        $page->getByRole('heading', ['name' => 'Projects dashboard', 'exact' => true])->waitFor();
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
        $this->page->locator('button[value="'.Action::SAVE_AND_RETURN.'"]')->click();
    }

    protected function deleteRecord(string $detailUrl): void
    {
        $this->page->goto($detailUrl);
        $this->page->locator('.action-delete')->click();
        $this->page->getByRole('button', ['name' => 'Confirm', 'exact' => true])->click();
        $this->page->locator('body.ea-index')->waitFor();
    }

    private function assertOwnedBrowserDatabaseUrl(string $databaseUrl): void
    {
        $parts = parse_url($databaseUrl);
        if (!\is_array($parts) || 'postgresql' !== ($parts['scheme'] ?? null)) {
            throw new \LogicException('Browser fixtures require a PostgreSQL DATABASE_URL.');
        }

        $user = rawurldecode((string) ($parts['user'] ?? ''));
        $pass = rawurldecode((string) ($parts['pass'] ?? ''));
        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        $host = (string) ($parts['host'] ?? '');
        $port = (string) ($parts['port'] ?? '5432');
        $expectedPort = $this->browserPublishedPostgresPort();

        if (
            'librarian' !== $user
            || 'browser-synthetic-only' !== $pass
            || 'librarian_browser' !== $path
            || !\in_array($host, ['127.0.0.1', 'localhost'], true)
            || $expectedPort !== $port
        ) {
            throw new \LogicException('Browser fixtures require the exact isolated checkout-owned Postgres URL.');
        }
    }

    private function browserPublishedPostgresPort(): string
    {
        $portFile = \dirname(__DIR__, 2).'/var/tooling/http-browser-postgres.port';
        if (is_link($portFile) || !is_file($portFile)) {
            throw new \LogicException('Missing checkout-owned browser Postgres published port.');
        }
        $port = trim((string) file_get_contents($portFile));
        if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
            throw new \LogicException('Invalid checkout-owned browser Postgres published port.');
        }

        return $port;
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
