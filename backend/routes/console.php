<?php

use App\Services\ReportCompletionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:run-due', function (ReportCompletionService $service) {
    $this->info('Processed '.$service->runDue().' due report schedule(s).');
})->purpose('Generate due in-app scheduled reports.');

Schedule::command('reports:run-due')->everyMinute()->withoutOverlapping();
