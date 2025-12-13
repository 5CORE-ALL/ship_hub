<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('bulk:send-summary')->everyMinute();
        // $schedule->command('amazon:process-reports')->everyMinute();
        $schedule->command('shipments:update-tracking')->everyMinute();
        $schedule->command('shipping:fetch-default-rates')->everyMinute();
        $schedule->command('ebay:sync-orders')->everyFiveMinutes(); 
        // $schedule->command('shopify:sync-orders')->cron('5,35 * * * *');   
        $schedule->command('walmart:sync-orders')->cron('10,40 * * * *');  
        $schedule->command('reverb:sync-orders')->cron('15,45 * * * *');   
        $schedule->command('macys:sync-orders')->cron('13,48 * * * *');   
        $schedule->command('aliexpress:sync-orders')->cron('20,50 * * * *');
        $schedule->command('amazon:sync-orders')->cron('10,40 * * * *');
        $schedule->command('tiktok:sync-order')->cron('12,42 * * * *');
        
        // $schedule->command('Sync orders from Shopify API (FiveCore Business)')->cron('20,50 * * * *'); 
        $schedule->command('app:sync-ship-station-carriers')->everyThirtyMinutes(); 
        $schedule->command('ebay:vtr-fetch')->hourly();
        // $schedule->command('amazon:fetch-reports')->everyThirtyMinutes(); 
;


        
        
        
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
