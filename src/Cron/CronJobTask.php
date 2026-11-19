<?php

declare(strict_types=1);

namespace App\Cron;

use App\Entity\CronJob;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Work performed for one cron job. Throws on failure; CronJobFailedException carries partial results.
 */
#[AutoconfigureTag]
interface CronJobTask
{
    public function job(): CronJob;

    /**
     * @param \Closure(): void $heartbeat call between long steps to keep the job lock alive
     *
     * @return list<string> results shown on the cron run, e.g. uploaded object URIs
     */
    public function run(\Closure $heartbeat): array;
}
