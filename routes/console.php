<?php
use App\Jobs\PublishOutbox;
use Illuminate\Support\Facades\Schedule;
Schedule::job(new PublishOutbox)->everyMinute()->withoutOverlapping();
