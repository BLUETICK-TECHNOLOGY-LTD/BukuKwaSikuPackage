<?php

namespace BukukwasikuPackage\Presentation\Http\Controllers;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class NafuuWalletController extends Controller
{
    //
    public function balance()
    {
        $transactionModel = Config::get('bukukwasiku.transaction.model');
        return Response::json([
            "success" => true,
            "balance" =>  $transactionModel::balance(Auth::user()),
        ]);
    }

    public function withdraw(Request $request)
    {
        $validate = Validator::make($request->all(), [
            "amount" => "required|numeric",
        ]);

        if ($validate->fails()) {
            return Response::json([
                "success" => false,
                "message" => $validate->errors()->first(),
                "errors" => $validate->errors()
            ]);
        }

        $user = Auth::user();
        try {
            DB::beginTransaction();
            $withdrawAmount = $request->amount;
            try {
                $nafuuWalletModel = Config::get('bukukwasiku.nafuu_wallet.model');
                $checkSavingChargesFn = Config::get('bukukwasiku.nafuu_wallet.checkSavingCharges');
                if (method_exists(new $nafuuWalletModel, $checkSavingChargesFn)) {
                    $fee = $nafuuWalletModel::$checkSavingChargesFn($withdrawAmount);
                }
            } catch (Exception $e) {
                return Response::json([
                    "success" => false,
                    "message" => $e->getMessage(),
                ], 200);
            }
            $totalWithdrawAmount = $withdrawAmount + $fee;
            $transactionModel = Config::get('bukukwasiku.transaction.model');
            $checkSufficientFn = Config::get('bukukwasiku.transaction.checkBalance');
            try {
                if (method_exists(new $transactionModel, $checkSufficientFn)) {
                    $transactionModel::$checkSufficientFn($user, $withdrawAmount);
                }
            } catch (Exception $e) {
                DB::rollBack();
                return Response::json([
                    'success' => false,
                    "message" => $e->getMessage(),
                ]);
            }

            $amount = $totalWithdrawAmount;
            if ($amount < 100) {
                DB::rollBack();
                return Response::json([
                    "success" => false,
                    "message" => "Minimum withdraw amount is 100",
                ], 200);
            }

            try {
                $transaction =  $transactionModel::transact(Auth::user(), $withdrawAmount, $transactionModel::WITHDRAW, "Withdraw amount: " . number_format($withdrawAmount), $user, false);
                $transactionModel::transact(Auth::user(), $fee, $transactionModel::CHARGES, "Charges amount: " . number_format($fee) . " for $transaction->transid", $transaction, false);
            } catch (Exception $e) {
                DB::rollBack();
                Log::error($e->getMessage());
                Log::error($e->getTraceAsString());
                return Response::json([
                    'success' => false,
                    "message" => "Internal Server Error",
                ]);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return Response::json([
                "success" => false,
                "message" => "Sorry, error occurred please try again later",
            ], 200);
        }
        $wallet = $transactionModel::balance($user);
        $smsService = Config::get('bukukwasiku.sms.service');
        $smsFunction = Config::get('bukukwasiku.sms.function');
        $smsMessage = Config::get('bukukwasiku.sms.sms');
        if (method_exists(new $smsService, $smsFunction)) {
            $smsMessage = str_replace(":amount", $withdrawAmount, $smsMessage);
            $smsMessage = str_replace(":bkb_reference", $wallet->reference, $smsMessage);
            $smsMessage = str_replace(":fee", $fee, $smsMessage);
            $smsService::$smsFunction($user->phone, $smsMessage);
        }
        return Response::json([
            "success" => true,
            "message" => "You will receive confirmation",
        ], 200);
    }

    public function depositWallet(Request $request, $paymentId = null)
    {
        $msisdn = str_replace('+', '', $request->msisdn);
        $msisdn = preg_replace('/^0/', '255', $msisdn);
        if (!preg_match('/^255/', $msisdn)) {
            $msisdn = '255' . ltrim($msisdn, '0');
        }
        $request->merge([
            "msisdn" => $msisdn,
            "amount" => str_replace(',', '', $request->amount),
        ]);

        $paymentModel = Config::get('bukukwasiku.payment.model');
        $payment = $paymentModel::whereId($paymentId)->first();
        if (!$payment) {
            $validate = Validator::make($request->all(), [
                "payment_type" => "required|string",
                "payment_method" => $request->payment_type == "manual" ? "" : "required",
                "reference" => $request->payment_type == "manual" ? "required|string" : "",
                "msisdn" => "required|string",
                "amount" => "required|numeric",
            ]);

            if ($validate->fails()) {
                return Response::json([
                    "success" => false,
                    "message" => $validate->errors()->first(),
                    "errors" => $validate->errors()
                ]);
            }
        }

        DB::beginTransaction();
        if ($payment) {
            Request::merge([
                'payment_type' => 'online'
            ]);
        } else {
            if ($request->amount < 100) {
                return Response::json(([
                    "success" => false,
                    "message" => "Amount must not be less than 100 TZS"
                ]), 401);
            }
            $user = Auth::user();
            $payment = $paymentModel::create([
                'type' => $request->payment_type,
                "reference" => $request->reference,
                "payable_type" => get_class($user),
                "payable_id" => $user->id,
                "msisdn" => $request->msisdn,
                "amount" => $request->amount,
                "payment_method" => $request->payment_method,
                "status" => 'pending',
            ]);
        }
        if (!$payment) {
            DB::rollBack();
            return Response::json(([
                "success" => false,
                "message" => "Failed to create payment"
            ]), 500);
        } else {
            if ($request->payment_type == "manual") {
                DB::commit();
                return Response::json([
                    "success" => true,
                    "message" => "Payment Received waiting for approval",
                    "data" => $payment
                ]);
            }
            $client = new Client([
                'verify' => false,
            ]);
            try {
                $whitelistedPhones = explode(',', env('WHITELISTED_PHONES'));
                $data = [
                    'reference' => 'WALLET' . $payment->id,
                    "msisdn" => $payment->msisdn,
                    "amount" => in_array($payment->msisdn, $whitelistedPhones) ? 100 : $payment->amount,
                    "network" => $payment->payment_method,
                    "callback" => Config::get('bukukwasiku.payment.payment_url'),
                ];
                $response = $client->request('POST', Config::get('bukukwasiku.payment.push_payment_url'), [
                    'form_params' => $data,
                ]);

                $resultData = $response->getBody()->getContents();
                if (!empty($resultData)) {
                    $results = json_decode($resultData, TRUE);
                    Log::info($results);
                    if (isset($results['success']) && $results['success'] === true) {
                        DB::commit();
                        return Response::json([
                            "success" => true,
                            "message" =>  $results['message'] ?? "",
                            "data" => $payment
                        ]);
                    } else {
                        DB::rollBack();
                        return Response::json([
                            "success" => false,
                            "message" =>  "Internal Server Error 1",
                        ], 500);
                    }
                } else {
                    DB::rollBack();
                    return Response::json([
                        "success" => false,
                        "message" =>  "Internal Server Error 2",
                    ], 500);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                return Response::json([
                    "success" => false,
                    "message" =>  $e->getMessage(),
                ]);
            }
        }
    }

    public function verify(Request $request)
    {
        $whitelistedIps = ['204.236.147.142'];
        if (!in_array($request->ip(), $whitelistedIps)) {
            Log::info('PAYMENT VERIFY: IP not authorized');
            return Response::json([
                "message" => "Unauthorized",
            ], 403);
        }
        try {
            $paymentModel = Config::get('bukukwasiku.payment.model');
            $transactionModel = Config::get('bukukwasiku.transaction.model');
            if ($request->has('status')) {
                if ($request->status == "success") {
                    if (!$request->has('reference') || empty($request->reference)) {
                        Log::info('PAYMENT VERIFY: Reference is required');
                        return Response::json([
                            "success" => false,
                            "message" => "Reference is required.",
                        ], 400);
                    }
                    $payment = $paymentModel::where('receipt', $request->payment_reference)->first();
                    if ($payment) {
                        Log::info('PAYMENT VERIFY: Payment has been utilized');
                        return Response::json([
                            "success" => false,
                            "message" => "Payment has been utilized.",
                        ], 404);
                    }

                    $request->merge([
                        'reference' => str_replace('WALLET', '', $request->reference),
                    ]);
                    $payment = $paymentModel::whereId($request->reference)->first();
                    if (!$payment) {
                        Log::info('PAYMENT VERIFY: Reference Not found');
                        return Response::json([
                            "success" => false,
                            "message" => "Reference Not found",
                        ], 404);
                    }
                    $payment->status        = $request->status;
                    $payment->receipt       = $request->payment_reference;
                    $payment->remarks       = $request->remarks;
                    $payment->save();

                    $user = $payment->payable;
                    $transactionModel::transact($user, $payment->amount, $transactionModel::DEPOSIT, $payment->remarks, $payment, true);

                    Log::info('PAYMENT VERIFY: Payment Succefully');
                    return Response::json([
                        "success" => true,
                        "message" => "Payment Succefully",
                    ], 200);
                }
            }
        } catch (Exception $e) {
            Log::info('PAYMENT VERIFY: ' . $e->getMessage());
            return Response::json([
                "success" => true,
                "message" => "Internal Server Error",
            ], 500);
        }
        Log::info('PAYMENT VERIFY: Payment Reference Not found');
        return Response::json([
            "success" => false,
            "message" => "Payment Reference Not found",
        ], 404);
    }
}
