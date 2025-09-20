<?php

namespace BukukwasikuPackage\Presentation\Http\Controllers;

use Illuminate\Support\Facades\Log;

abstract class Controller
{
    //
    public function logError($message, $title = "BUKUKWA SIKU PACKAGE")
    {
        if (env('APP_LOG_ERROR', false)) {
            Log::error("$title : $message");
        }
    }

    public static function sLogError($message, $title = "BUKUKWA SIKU PACKAGE")
    {
        if (env('APP_LOG_ERROR', false)) {
            Log::error("$title : $message");
        }
    }
}
