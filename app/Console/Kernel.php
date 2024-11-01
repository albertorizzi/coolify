<?php

namespace App\Console;

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\ScheduledTaskJob;
use App\Jobs\ServerCheckJob;
use App\Jobs\ServerCleanupMux;
use App\Jobs\UpdateCoolifyJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;

class Kernel extends ConsoleKernel
{
    private $allServers;

    protected function schedule(Schedule $schedule): void
    {
        $this->allServers = Server::all();
        $settings = instanceSettings();

        $schedule->job(new CleanupStaleMultiplexedConnections)->hourly();

        if (isDev()) {
            // Instance Jobs
            $schedule->command('horizon:snapshot')->everyMinute();
            $schedule->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            // Server Jobs
            $this->checkScheduledBackups($schedule);
            $this->checkResources($schedule);
            $this->checkScheduledTasks($schedule);
            $schedule->command('uploads:clear')->everyTwoMinutes();

            $schedule->command('telescope:prune')->daily();

            $schedule->job(new CheckHelperImageJob)->everyFiveMinutes()->onOneServer();
        } else {
            // Instance Jobs
            $schedule->command('horizon:snapshot')->everyFiveMinutes();
            $schedule->command('cleanup:unreachable-servers')->daily()->onOneServer();
            $schedule->job(new PullTemplatesFromCDN)->cron($settings->update_check_frequency)->timezone($settings->instance_timezone)->onOneServer();
            $schedule->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->scheduleUpdates($schedule);

            // Server Jobs
            $this->checkScheduledBackups($schedule);
            $this->checkResources($schedule);
            $this->pullImages($schedule);
            $this->checkScheduledTasks($schedule);

            $schedule->command('cleanup:database --yes')->daily();
            $schedule->command('uploads:clear')->everyTwoMinutes();
        }
    }

    private function pullImages($schedule): void
    {
        $settings = instanceSettings();
        $servers = $this->allServers->where('settings.is_usable', true)->where('settings.is_reachable', true)->where('ip', '!=', '1.2.3.4');
        foreach ($servers as $server) {
            if ($server->isSentinelEnabled()) {
                $schedule->job(function () use ($server) {
                    CheckAndStartSentinelJob::dispatch($server);
                })->cron($settings->update_check_frequency)->timezone($settings->instance_timezone)->onOneServer();
            }
        }
        $schedule->job(new CheckHelperImageJob)
            ->cron($settings->update_check_frequency)
            ->timezone($settings->instance_timezone)
            ->onOneServer();
    }

    private function scheduleUpdates($schedule): void
    {
        $settings = instanceSettings();

        $updateCheckFrequency = $settings->update_check_frequency;
        $schedule->job(new CheckForUpdatesJob)
            ->cron($updateCheckFrequency)
            ->timezone($settings->instance_timezone)
            ->onOneServer();

        if ($settings->is_auto_update_enabled) {
            $autoUpdateFrequency = $settings->auto_update_frequency;
            $schedule->job(new UpdateCoolifyJob)
                ->cron($autoUpdateFrequency)
                ->timezone($settings->instance_timezone)
                ->onOneServer();
        }
    }

    private function checkResources($schedule): void
    {
        if (isCloud()) {
            $servers = $this->allServers->whereNotNull('team.subscription')->where('team.subscription.stripe_trial_already_ended', false)->where('ip', '!=', '1.2.3.4');
            $own = Team::find(0)->servers;
            $servers = $servers->merge($own);
        } else {
            $servers = $this->allServers->where('ip', '!=', '1.2.3.4');
        }
        foreach ($servers as $server) {
            $lastSentinelUpdate = $server->sentinel_updated_at;
            $serverTimezone = $server->settings->server_timezone;
            if (Carbon::parse($lastSentinelUpdate)->isBefore(now()->subSeconds($server->waitBeforeDoingSshCheck()))) {
                $schedule->job(new ServerCheckJob($server))->everyMinute()->onOneServer();
            }
            if ($server->settings->force_docker_cleanup) {
                $schedule->job(new DockerCleanupJob($server))->cron($server->settings->docker_cleanup_frequency)->timezone($serverTimezone)->onOneServer();
            } else {
                $schedule->job(new DockerCleanupJob($server))->everyTenMinutes()->timezone($serverTimezone)->onOneServer();
            }
            // Cleanup multiplexed connections every hour
            $schedule->job(new ServerCleanupMux($server))->hourly()->onOneServer();

            // Temporary solution until we have better memory management for Sentinel
            if ($server->isSentinelEnabled()) {
                $schedule->job(function () use ($server) {
                    $server->restartContainer('coolify-sentinel');
                })->daily()->onOneServer();
            }
        }
    }

    private function checkScheduledBackups($schedule): void
    {
        $scheduled_backups = ScheduledDatabaseBackup::all();
        if ($scheduled_backups->isEmpty()) {
            return;
        }
        foreach ($scheduled_backups as $scheduled_backup) {
            if (! $scheduled_backup->enabled) {
                continue;
            }
            if (is_null(data_get($scheduled_backup, 'database'))) {
                $scheduled_backup->delete();

                continue;
            }

            $server = $scheduled_backup->server();

            if (! $server) {
                continue;
            }
            $serverTimezone = $server->settings->server_timezone;

            if (isset(VALID_CRON_STRINGS[$scheduled_backup->frequency])) {
                $scheduled_backup->frequency = VALID_CRON_STRINGS[$scheduled_backup->frequency];
            }
            $schedule->job(new DatabaseBackupJob(
                backup: $scheduled_backup
            ))->cron($scheduled_backup->frequency)->timezone($serverTimezone)->onOneServer();
        }
    }

    private function checkScheduledTasks($schedule): void
    {
        $scheduled_tasks = ScheduledTask::all();
        if ($scheduled_tasks->isEmpty()) {
            return;
        }
        foreach ($scheduled_tasks as $scheduled_task) {
            if ($scheduled_task->enabled === false) {
                continue;
            }
            $service = $scheduled_task->service;
            $application = $scheduled_task->application;

            if (! $application && ! $service) {
                $scheduled_task->delete();

                continue;
            }
            if ($application) {
                if (str($application->status)->contains('running') === false) {
                    continue;
                }
            }
            if ($service) {
                if (str($service->status())->contains('running') === false) {
                    continue;
                }
            }

            $server = $scheduled_task->server();
            if (! $server) {
                continue;
            }
            $serverTimezone = $server->settings->server_timezone ?: config('app.timezone');

            if (isset(VALID_CRON_STRINGS[$scheduled_task->frequency])) {
                $scheduled_task->frequency = VALID_CRON_STRINGS[$scheduled_task->frequency];
            }
            $schedule->job(new ScheduledTaskJob(
                task: $scheduled_task
            ))->cron($scheduled_task->frequency)->timezone($serverTimezone)->onOneServer();
        }
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
