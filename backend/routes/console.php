<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('leave:governance')->weekdays()->hourly()->between('08:00','17:00')->withoutOverlapping();
Schedule::command('leave:governance --purge')->monthlyOn(1,'03:00')->withoutOverlapping();
Schedule::command('app:carry-forward-leaves')->yearlyOn(10,1,'00:10')->withoutOverlapping();
Artisan::command('inspire',function(){$this->comment(Inspiring::quote());})->purpose('Display an inspiring quote');
