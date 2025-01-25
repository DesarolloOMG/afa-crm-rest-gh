<?php

namespace App\Http\Services;

use Illuminate\Support\Carbon;

class LoggerService
{
    public static function writeLog($archivo, $mensaje)
    {
        $archivosMap = [
            'developer' => "logs/developer.log",
            't1' => "logs/t1.log",
            'pda' => "logs/pda.log",
            'amazon' => "logs/amazon-log.log",
        ];

        $file = $archivosMap[$archivo] ?? "logs/default.log";

        file_put_contents($file, Carbon::now() . ' ' . $mensaje . PHP_EOL, FILE_APPEND);
    }
}
