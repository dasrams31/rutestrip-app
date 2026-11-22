<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Admin activity log keeps 180 days (ActivityLog::prunable). Needs the scheduler
// cron on the server (* * * * * php artisan schedule:run), or run by hand:
//   php artisan model:prune --model="App\Models\ActivityLog"
Schedule::command('model:prune', ['--model' => [\App\Models\ActivityLog::class, \App\Models\LiveShare::class]])->daily();
