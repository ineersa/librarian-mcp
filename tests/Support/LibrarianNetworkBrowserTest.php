<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
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
        $database = $root.'/var/browser-live/application.db';
        if ('test' !== ($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) || 'sqlite:///'.$database !== getenv('DATABASE_URL')) {
            throw new \LogicException('Browser fixtures require the exact isolated test database.');
        }
        foreach ([$root.'/var', \dirname($database), $database] as $path) {
            if (is_link($path)) {
                throw new \LogicException('Refusing symlinked browser fixture storage.');
            }
        }
        self::bootKernel();
        // RefreshDatabaseTrait loads Alice fixtures, then opens a transaction.
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
