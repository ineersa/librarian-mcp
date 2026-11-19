<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Backup\DailyBackupTask;
use App\Tests\Support\LibrarianNetworkBrowserTest;
use App\Tests\Support\ProjectSourceBrowserHelpers;
use App\Tests\Support\ProjectSourceFixture;
use Playwright\Assertions\Expect;
use Playwright\Locator\LocatorInterface;

/**
 * Cron runs journey: queue a forced backup from the admin, watch the run succeed, inspect its
 * uploads, and check the archives in the test-env local storage stand-in (never GCS).
 */
final class CronRunTest extends LibrarianNetworkBrowserTest
{
    use ProjectSourceBrowserHelpers;

    /** Matches BACKUP_LOCAL_STORAGE_DIR for the browser worker (compose.browser.yaml). */
    private const string STORAGE_ROOT = '/app/var/browser-live/backup-storage';
    private const int RUN_TIMEOUT_SECONDS = 180;

    public function testRunBackupNowRecordsRunAndStoresArchives(): void
    {
        $slug = 'default-om';
        $builderPath = ProjectSourceFixture::builderProjectPath($slug);
        $before = ProjectSourceFixture::hashTree($builderPath);

        $this->login();
        $this->registerProject('Backup Fixture', ProjectSourceFixture::visibleProjectPath($slug));

        $page = $this->visit('/admin/cron-runs');
        $page->getByRole('heading', ['name' => 'Cron runs', 'exact' => true])->waitFor();
        $previousRuns = $page->locator('tbody tr[data-id]')->count();

        // Forced: runs even when a backup already succeeded today, so it is repeatable.
        $page->getByRole('button', ['name' => 'Run backup now'])->click();
        $page->waitForURL('**/admin/cron-runs**');
        $this->assertPageContains('Backup queued.');

        $run = $this->waitForNewestRunFinished($previousRuns);
        Expect::locator($run)->toContainText('Daily backup');
        Expect::locator($run)->toContainText('Succeeded');

        $run->locator('td')->first()->click();
        $this->page->waitForURL('**/admin/cron-runs/**');
        $detail = $this->page->locator('body');
        Expect::locator($detail)->toContainText(DailyBackupTask::PREFIX_POSTGRES.'postgres_');
        Expect::locator($detail)->toContainText(DailyBackupTask::PREFIX_HATFIELD.'backup-fixture/hatfield_backup-fixture_');

        self::assertNotEmpty(glob(self::STORAGE_ROOT.'/'.DailyBackupTask::PREFIX_POSTGRES.'postgres_*.dump'));
        $archives = glob(self::STORAGE_ROOT.'/'.DailyBackupTask::PREFIX_HATFIELD.'backup-fixture/*.tar.gz');
        self::assertNotFalse($archives);
        self::assertNotEmpty($archives);
        $members = $this->archiveMembers((string) end($archives));
        self::assertContains('.hatfield/sessions/108/events.jsonl', $members);
        self::assertContains('.hatfield/state.sqlite', $members);
        self::assertNotContains('.hatfield/logs/agent.log', $members);
        self::assertNotContains('.hatfield/extensions-data/observational-memory/om.sqlite', $members);
        $this->assertStateSnapshotReadable((string) end($archives));

        ProjectSourceFixture::assertUnchanged($before, ProjectSourceFixture::hashTree($builderPath), 'default-om after backup');
    }

    /**
     * Run rows are not live-updated; reload until the newest run leaves `Running`.
     */
    private function waitForNewestRunFinished(int $previousRuns): LocatorInterface
    {
        $deadline = time() + self::RUN_TIMEOUT_SECONDS;
        do {
            $page = $this->visit('/admin/cron-runs');
            $rows = $page->locator('tbody tr[data-id]');
            if ($rows->count() > $previousRuns && !str_contains((string) $rows->first()->textContent(), 'Running')) {
                return $rows->first();
            }
            usleep(2_000_000);
        } while (time() < $deadline);

        self::fail('The queued backup run did not finish in time.');
    }

    /** @return list<string> */
    private function archiveMembers(string $archive): array
    {
        $members = [];
        $prefix = 'phar://'.$archive.'/';
        $iterator = new \RecursiveIteratorIterator(new \PharData($archive), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $file) {
            \assert($file instanceof \PharFileInfo);
            $members[] = substr($file->getPathname(), \strlen($prefix));
        }

        return $members;
    }

    private function assertStateSnapshotReadable(string $archive): void
    {
        $target = sys_get_temp_dir().'/cron-run-test-'.bin2hex(random_bytes(6));
        mkdir($target);
        try {
            (new \PharData($archive))->extractTo($target, '.hatfield/state.sqlite');
            $db = new \SQLite3($target.'/.hatfield/state.sqlite', \SQLITE3_OPEN_READONLY);
            self::assertSame('Synthetic session 108', $db->querySingle('SELECT name FROM hatfield_session WHERE id = 108'));
            $db->close();
        } finally {
            @unlink($target.'/.hatfield/state.sqlite');
            @rmdir($target.'/.hatfield');
            @rmdir($target);
        }
    }
}
