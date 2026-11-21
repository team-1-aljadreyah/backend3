<?php

use App\Support\BaghdadTime;
use Illuminate\Support\Facades\Schedule;

Schedule::command('app:send-daily-reminders')
    ->dailyAt('08:00')
    ->timezone(BaghdadTime::timezone())
    ->withoutOverlapping();
