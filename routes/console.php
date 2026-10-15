<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('temperature:import-reference')->everyTenMinutes()->withoutOverlapping();
