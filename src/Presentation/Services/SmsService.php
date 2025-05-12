<?php

namespace BukukwasikuPackage\Presentation\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Config;

class SmsService
{
    //
    public static function sendSms($phone, $message)
    {
        try {
            $client = new Client();
            $link = Config::get('bukukwasiku.sms.link', 'https://shushatanga.smartpay.co.tz/api/v1/send-sms');
            $headers = Config::get('bukukwasiku.sms.headers', ['accept' => 'application/json']);
            $params = Config::get('bukukwasiku.sms.params', ['phone' => $phone,'sms' => $message,'sender' => "BLUETICK",'token' => '145142365315gsafdg']);     
            $response = $client->request('POST', $link, [
                'headers' => $headers,
                'form_params' => $params + [
                    'phone' => $phone,
                    'sms' => $message,
                ],
            ]);
        } catch (\Exception $e) {
        }
    }
}
