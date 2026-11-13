<?php

use App\Jobs\ExpireRideOffersJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ride-offers:expire', function () {
    ExpireRideOffersJob::dispatchSync();

    $this->info('Expired ride offers processed.');
})->purpose('Expire ride offers past their expires_at');

Schedule::job(new ExpireRideOffersJob)->everyMinute();
