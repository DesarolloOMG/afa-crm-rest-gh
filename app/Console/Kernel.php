<?php

namespace App\Console;

use App\Console\Commands\SyncNexfiraInvoices;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        SyncNexfiraInvoices::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        if (config('nexfira.polling.enabled', true)) {
            $schedule->command('nexfira:sync-active')
                ->everyMinute()
                ->withoutOverlapping(10);
        }
    }
}
