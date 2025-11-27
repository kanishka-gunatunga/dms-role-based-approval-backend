<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Documents;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::call(function () {
    $today = Carbon::now();
    $expiredDocuments = Documents::where('expiration_date', '<', $today)->where('uploaded_method','direct')->get();
    foreach ($expiredDocuments as $document) {
        $document->delete();
        Log::info("Deleted document: {$document->name}");
    }
})->daily();