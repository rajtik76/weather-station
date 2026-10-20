<?php

use App\ValueObject\LocalTime;
use Illuminate\Support\Facades\Schedule;

Schedule::command('temperature:import-reference')->everyTenMinutes()->withoutOverlapping();
Schedule::command('temperature:validate')->dailyAt('00:10')->timezone(LocalTime::TIMEZONE)->withoutOverlapping();
Schedule::command('temperature:retrain')->weeklyOn(1, '00:15')->timezone(LocalTime::TIMEZONE)->withoutOverlapping();
