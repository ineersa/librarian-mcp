<?php

declare(strict_types=1);

namespace dev;

use Castor\Attribute\AsTask;
use function Castor\run;
use function CastorTasks\dev_compose;
use function CastorTasks\dev_compose_interactive;
use function CastorTasks\dev_php_exec;
use function CastorTasks\ensure_data_dir;
use function CastorTasks\is_llm_mode;
use function CastorTasks\persist_process_output;
use function CastorTasks\phpunit_inputs_available;
use function CastorTasks\relative_report_path;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;
use function CastorTasks\summarize_junit_xml;
use function CastorTasks\summarize_php_cs_fixer_json;
use function CastorTasks\summarize_phpstan_json;
use function CastorTasks\write_empty_junit_report;
use function CastorTasks\stop_conflicting_dev_port_containers;

#[AsTask(description: 'Ensure local data directory and SQLite file exist')]
function init(): void
{
    ensure_data_dir();
}

#[AsTask(description: 'One-shot local setup (then: castor dev:bootstrap, castor dev:messenger-consume)')]
function setup(): void
{
    init();
    stop_conflicting_dev_port_containers();
    build();
    up();
    composer_install();
}

#[AsTask(description: 'After first up: run importmap install and Tailwind build')]
function bootstrap(): void
{
    stop_conflicting_dev_port_containers();
    dev_php_exec('php bin/console importmap:install');
    dev_php_exec("php -r \"if (gethostbyname('github.com') === 'github.com') {fwrite(STDERR, 'DNS lookup failed for github.com inside php container. Check Docker DNS config (DOCKER_DNS_PRIMARY / DOCKER_DNS_SECONDARY).'.PHP_EOL); exit(2);} \"");
    dev_php_exec('php -r "\$binaries = glob(\'var/tailwind/*/tailwindcss-*\'); if (\$binaries === false) { \$binaries = []; } foreach (\$binaries as \$binary) { if (!is_file(\$binary)) { continue; } if (filesize(\$binary) > 0 && is_executable(\$binary)) { continue; } @unlink(\$binary); fwrite(STDERR, \"Removed invalid Tailwind binary: \".\$binary.PHP_EOL); }"');
    dev_php_exec('php bin/console tailwind:build');
}

#[AsTask(description: 'Build local development images')]
function build(): void
{
    dev_compose('build');
}

#[AsTask(description: 'Start local development stack')]
function up(): void
{
    init();
    stop_conflicting_dev_port_containers();
    dev_compose('up -d --build');
}

#[AsTask(description: 'Stop local development stack')]
function down(): void
{
    dev_compose('down');
}

#[AsTask(description: 'Stop local services without removing containers')]
function stop(): void
{
    dev_compose('stop');
}

#[AsTask(description: 'Restart local development stack')]
function restart(): void
{
    stop_conflicting_dev_port_containers();
    dev_compose('restart');
}

#[AsTask(description: 'Show local service status')]
function ps(): void
{
    dev_compose('ps');
}

#[AsTask(description: 'Stream all local logs')]
function logs(): void
{
    dev_compose('logs -f');
}

#[AsTask(description: 'Stream PHP service logs (local)')]
function logs_php(): void
{
    dev_compose('logs -f php');
}

#[AsTask(description: 'Stream Mailpit logs (local)')]
function logs_mailer(): void
{
    dev_compose('logs -f mailer');
}

#[AsTask(description: 'Pull latest base images')]
function pull(): void
{
    dev_compose('pull');
}

#[AsTask(description: 'Remove local stack and orphan containers')]
function prune(): void
{
    dev_compose('down --remove-orphans');
}

#[AsTask(description: 'Open shell in PHP container as current user (host TTY + docker -it for readline / tab completion)')]
function sh(): void
{
    // Castor must use Context::withTty(true); otherwise docker never gets a real PTY even with -it.
    dev_compose_interactive('exec -it -u $(id -u):$(id -g) php bash');
}

#[AsTask(description: 'Open root shell in PHP container (host TTY + docker -it)')]
function root_sh(): void
{
    dev_compose_interactive('exec -it php bash');
}

#[AsTask(description: 'Regenerate .castor/mate.generated.php from mate mcp:tools:list (run after Mate extension changes)')]
function mate_generate_castor(): void
{
    $root = \dirname(__DIR__);
    run('php '.escapeshellarg($root.'/.castor/bin/generate-mate-castor-tasks.php'));
}

#[AsTask(description: 'Run composer command in local container')]
function composer(string $cmd): void
{
    dev_php_exec('composer ' . $cmd);
}

#[AsTask(description: 'Run ai-index command in local container')]
function ai_index(string $cmd): void
{
    dev_php_exec('php vendor/bin/ai-index ' . $cmd);
}

#[AsTask(description: 'Install PHP dependencies (works with or without a running container)')]
function composer_install(): void
{
    dev_compose('run --rm --entrypoint="" -u $(id -u):$(id -g) php composer install --no-interaction');
}

#[AsTask(description: 'Update PHP dependencies in running local container')]
function composer_update(): void
{
    dev_php_exec('composer update');
}

#[AsTask(description: 'Run Symfony console command in local container')]
function console(string $cmd): void
{
    dev_php_exec('php bin/console ' . $cmd);
}

#[AsTask(description: 'Run Messenger consumer for all non-failed transports')]
function messenger_consume(): void
{
    dev_php_exec('php bin/console messenger:consume --all --exclude-receivers=failed -vv');
}

#[AsTask(description: 'Clear async and failed Messenger queues')]
function messenger_clear(): void
{
    dev_php_exec('php bin/console dbal:run-sql "DELETE FROM messenger_messages"');
    dev_php_exec('php bin/console messenger:failed:remove --all --no-interaction');
}

#[AsTask(description: 'Run PHPUnit tests in local container (LLM_MODE=true => concise output + JUnit report)')]
function test(): void
{
    if (!phpunit_inputs_available()) {
        write_empty_junit_report('phpunit.junit.xml');
        echo \sprintf(
            'test: skipped (no phpunit config/tests); junit=%s',
            relative_report_path('phpunit.junit.xml')
        ).\PHP_EOL;

        return;
    }

    if (!is_llm_mode()) {
        dev_php_exec('php bin/phpunit');

        return;
    }

    $junitPath = report_path('phpunit.junit.xml');
    $command = \sprintf(
        'vendor/bin/phpunit --colors=never --no-progress --no-results --log-junit %s',
        escapeshellarg($junitPath)
    );

    $process = run_quiet_command($command);
    persist_process_output($process, 'phpunit.log');

    $summary = summarize_junit_xml($junitPath);

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('test failed (%s); junit=%s; log=%s', $summary, relative_report_path('phpunit.junit.xml'), relative_report_path('phpunit.log')));
    }

    echo \sprintf(
        'test: ok (%s); junit=%s',
        $summary,
        relative_report_path('phpunit.junit.xml')
    ).\PHP_EOL;
}

#[AsTask(description: 'Run PHPUnit with coverage reports (text, HTML, Clover)')]
function test_coverage(): void
{
    dev_compose('exec php sh -lc "mkdir -p /app/.phpunit.cache/code-coverage /app/var/coverage/html && chown -R $(id -u):$(id -g) /app/.phpunit.cache /app/var/coverage"');
    dev_compose('exec -e XDEBUG_MODE=coverage -u $(id -u):$(id -g) php php bin/phpunit --coverage-text --coverage-html var/coverage/html --coverage-clover var/coverage/clover.xml');
}

#[AsTask(description: 'Run PHP CS Fixer in local container (LLM_MODE=true => concise output)')]
function cs_fix(): void
{
    $command = 'php vendor/bin/php-cs-fixer fix';

    if (!is_llm_mode()) {
        dev_php_exec($command);

        return;
    }

    $process = run_quiet_command($command.' --format=json --show-progress=none --no-ansi');
    persist_process_output($process, 'php-cs-fixer.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('php-cs-fixer.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_php_cs_fixer_json($stdout);

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('cs-fix failed (%s); report=%s; log=%s', $summary, relative_report_path('php-cs-fixer.json'), relative_report_path('php-cs-fixer.log')));
    }

    echo \sprintf(
        'cs-fix: ok (%s)',
        $summary
    ).\PHP_EOL;
}

#[AsTask(description: 'Run PHPStan in local container (LLM_MODE=true => concise output + JSON report)')]
function phpstan(): void
{
    dev_compose('exec php sh -lc "mkdir -p /app/var/phpstan && chown -R $(id -u):$(id -g) /app/var/phpstan"');

    $command = 'php vendor/bin/phpstan analyse -c phpstan.dist.neon';

    if (!is_llm_mode()) {
        dev_php_exec($command);

        return;
    }

    $process = run_quiet_command($command.' --error-format=json --no-progress --no-ansi');
    persist_process_output($process, 'phpstan.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('phpstan.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_phpstan_json($stdout);

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('phpstan failed (%s); report=%s; log=%s', $summary, relative_report_path('phpstan.json'), relative_report_path('phpstan.log')));
    }

    echo \sprintf(
        'phpstan: ok (%s)',
        $summary
    ).\PHP_EOL;
}

#[AsTask(description: 'Run cs-fix, phpstan, and tests')]
function quality(): void
{
    cs_fix();
    phpstan();
    test();
}

#[AsTask(description: 'Run lightweight local sanity checks')]
function check(): void
{
    dev_php_exec('php -v');
    dev_php_exec('php bin/console about');
}

#[AsTask(description: 'Validate local compose configuration')]
function config(): void
{
    dev_compose('config');
}
