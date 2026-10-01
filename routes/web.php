<?php

declare(strict_types=1);

use App\Livewire\Charts;
use App\Livewire\Forecast;
use App\Livewire\Overview;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Overview::class)->name('overview');
Route::livewire('/charts', Charts::class)->name('charts');
Route::livewire('/forecast', Forecast::class)->name('forecast');
