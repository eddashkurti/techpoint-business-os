<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('techpoint:woo-reconcile')->hourly()->withoutOverlapping();
Schedule::command('techpoint:reconcile --json')->daily()->withoutOverlapping();
