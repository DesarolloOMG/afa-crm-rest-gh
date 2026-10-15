<?php

namespace App\Http\Services\Nexfira;

use Carbon\Carbon;

class FiscalClock
{
    public static function now($timezone)
    {
        $utc = Carbon::now('UTC');
        $local = $utc->copy()->setTimezone($timezone);
        // PHP 7.3 can bundle tzdb 2020.1, which still applies Mexican DST.
        // Mexico City has stayed at UTC-06 since the final 2022 transition.
        // Keep historical rules and other fiscal locations unchanged.
        if ($timezone === 'America/Mexico_City'
            && $utc->format('Y-m-d\TH:i:s') >= '2022-10-30T07:00:00'
            && $local->getOffset() !== -21600) {
            $local = $utc->copy()->setTimezone('-06:00');
        }
        return $local;
    }
}
