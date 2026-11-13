<?php

use App\Jobs\AggregateDailyOfferMetricsJob;
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

Artisan::command('ride-offers:aggregate {--date=}', function () {
    $date = $this->option('date');
    $date = is_string($date) ? $date : null;

    AggregateDailyOfferMetricsJob::dispatchSync($date);

    $this->info('Daily offer metrics aggregated.');
})->purpose('Aggregate daily offer metrics');

Schedule::job(new ExpireRideOffersJob)->everyMinute();
Schedule::job(new AggregateDailyOfferMetricsJob)->daily();
