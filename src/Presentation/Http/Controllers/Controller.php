<?php

namespace BukukwasikuPackage\Presentation\Http\Controllers;

use Illuminate\Support\Facades\Log;

abstract class Controller
{
    //
    public function logError($title = "BUKUKWA SIKU PACKAGE", $message)
    {
        if (env('APP_LOG_ERROR', false)) {
            Log::error("$title : $message");
        }
    }
}
