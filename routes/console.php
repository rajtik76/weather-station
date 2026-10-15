<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('temperature:import-reference')->hourlyAt(15)->withoutOverlapping();
