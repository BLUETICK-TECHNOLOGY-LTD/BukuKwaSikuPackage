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

class BukuKwaSikuPayment extends Controller
{
    //
    public function makePayment(Request $request, $paymentId = null)
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

        $paymentConfigModel = Config::get('bukukwasiku.payment.model');
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        $payment = $paymentConfigModel::whereId($paymentId)->first();
        if (!$payment) {
            if (!$request->has('references')) {
                $validate = Validator::make($request->all(), [
                    "product_reference" => "required|exists:order_items,bkb_reference",
                    "payment_type" => "required|string",
                    "payment_method" => $request->payment_type == "manual" ? "" : "required",
                    "reference" => $request->payment_type == "manual" ? "required|string" : "",
                    "msisdn" => "required|string",
                    "amount" => "required|numeric",
                ]);
            } else {
                $validate = Validator::make($request->all(), [
                    "references" => "required",
                    "payment_type" => "required|string",
                    "payment_method" => $request->payment_type == "manual" ? "" : "required",
                    "reference" => $request->payment_type == "manual" ? "required|string" : "",
                    "msisdn" => "required|string",
                    "amount" => "required|numeric",
                ]);
                try {
                    if (is_string($request->references)) {
                        $request->merge([
                            'references' => json_decode($request->references, true)
                        ]);
                    }
                } catch (Exception $e) {
                    return Response::json([
                        "success" => false,
                        "message" => $e->getMessage()
                    ], 400);
                }
            }

            if ($validate->fails()) {
                return Response::json([
                    "success" => false,
                    "message" => $validate->errors()->first(),
                    "errors" => $validate->errors()
                ]);
            }

            DB::beginTransaction();
            if (!$request->has('references')) {
                $product = $orderItemConfigModel::withoutGlobalScopes()->where('bkb_reference', $request->product_reference)->first();
                $payment = $paymentConfigModel::create([
                    "type" => $request->payment_type,
                    "payable_id" => $product->id,
                    "payable_type" => $orderItemConfigModel,
                    "payment_method" => $request->payment_type == "manual" ? 4 : $request->payment_method,
                    "msisdn" => $request->msisdn,
                    "amount" => $request->amount,
                    "reference" => $request->reference,
                ]);
            } else {
                $references = [];
                $totalAmount = 0;
                $product = null;
                foreach ($request->references as $reference) {
                    $product = $orderItemConfigModel::withoutGlobalScopes()->where('bkb_reference', $reference['reference'])->first();
                    if ($product) {
                        if ($product->bkb_reference != "") {
                            $references[] = $product->reference;
                            $totalAmount += $reference['amount'];
                        }
                    }
                }
                if ($product == null) {
                    DB::rollBack();
                    return Response::json([
                        "success" => false,
                        "message" => "Product Not Found",
                    ]);
                }
                if ($totalAmount <= Config::get("bukukwasiku.payment.minimum_payment", 100)) {
                    DB::rollBack();
                    return Response::json([
                        "success" => false,
                        "message" => "Minimum amount is " . Config::get("bukukwasiku.payment.minimum_payment", 100),
                    ]);
                }
                $payment = $paymentConfigModel::create([
                    "type" => $request->payment_type,
                    "payable_id" => $product->id,
                    "payable_type" => $orderItemConfigModel,
                    "payment_method" => $request->payment_type == "manual" ? 4 : $request->payment_method,
                    "reference" => $request->reference,
                    "msisdn" => $request->msisdn,
                    "amount" => $totalAmount,
                    "references" => json_encode($request->references),
                ]);
            }
        }

        if (!$payment) {
            DB::rollBack();
            return Response::json([
                "success" => false,
                "message" => "Payment Failed to create",
            ]);
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
                if ($payment->payment_method == 3) {
                    $payable = $payment->payable;
                    if ($payable) {
                        if (get_class($payable) == $orderItemConfigModel) {
                            $order = $payable->vendorOrder->order ?? null;
                            if (!$order) {
                                return Response::json([
                                    "success" => false,
                                    "message" => "Order not found",
                                ]);
                            }
                            try {
                                $t = null;
                                return $this->walletPayment($payable, $order, $payment, $t);
                            } catch (Exception $e) {
                                return Response::json([
                                    "success" => false,
                                    "message" => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                    DB::rollBack();
                    return Response::json([
                        "success" => false,
                        "message" => "Payment Failed",
                    ]);
                }
                $whitelistedPhones = explode(',', env('WHITELISTED_PHONES'));
                $data = [
                    'reference' => 'BKST2' . $payment->id,
                    "msisdn" => $payment->msisdn,
                    "amount" => in_array($payment->msisdn, $whitelistedPhones) ? 100 : $payment->amount,
                    "network" => $payment->payment_method,
                    "callback" => Config::get('bukukwasiku.payment.bukukwasiku_payment_callback'),
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
                        ]);
                    }
                } else {
                    DB::rollBack();
                    return Response::json([
                        "success" => false,
                        "message" =>  "Internal Server Error 2",
                    ]);
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

    public function walletPayment($orderItem, $order, $paymentModel, &$transaction,  $user = null)
    {
        $transactionConfigModel = Config::get('bukukwasiku.transaction.model');
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        $user = $user ?? Auth::user();
        $transaction = $transactionConfigModel::transact($user, $paymentModel->amount, $transactionConfigModel::USE, "Payment of Order: " . $order->reference, $orderItem, false);

        $paymentModel->status        = "Success";
        $paymentModel->receipt       = $transaction->transid;
        $paymentModel->remarks       = "FROM WALLET " . "Payment of Order: " . $order->reference;
        $paymentModel->save();

        $payment = [
            "id" => $transaction->id,
            "reference" => $transaction->transid,
            "amount" => $transaction->amount,
            "status" => "Success",
            "msisdn" => $user->phone,
            "receipt" => $transaction->transid,
            "remarks" => $paymentModel->remarks,
            "payable_type" => get_class($transaction),
            "payable_id" => $transaction->id,
            "payment_method" => 3,
            "method_id" => 3,
        ];

        $client = new Client([
            'verify' => false,
        ]);

        $params = [];
        $references = [];
        if ($paymentModel->references) {
            $references = json_decode($paymentModel->references, true);
            foreach ($references as $reference) {
                $product = $orderItemConfigModel::withoutGlobalScopes()->where('bkb_reference', $reference['reference'])->first();
                if ($product) {
                    $payment['payment'] = $payment;
                    $payment['amount'] = $reference['amount'];
                    $params[] = [
                        "payment" => $payment,
                        "amount" => $payment['amount'],
                        "reference" => $product->bkb_reference,
                    ];
                    $references[] = $product->bkb_reference;
                }
            }
        } else {
            $params[] = [
                "payment" => $payment,
                "amount" => $transaction->amount,
                "reference" => $orderItem->bkb_reference,
            ];
            $references[] = $orderItem->bkb_reference;
        }

        $response = $client->request('POST', Config::get('bukukwasiku.bukukwasiku.save_payment'), [
            'form_params' => [
                "products" => $params,
                "references" => $references
            ],
        ]);

        $resultData = $response->getBody()->getContents();
        if (!empty($resultData)) {
            $results = json_decode($resultData, TRUE);
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
                ]);
            }
        } else {
            DB::rollBack();
            return Response::json([
                "success" => false,
                "message" =>  "Internal Server Error 2",
            ]);
        }
    }


    public function verify(Request $request, $commit = true)
    {

        $whitelistedIps = explode(',', env("WHITELISTED_IPS"));
        Log::error("IP: " . $request->ip());
        if (!in_array($request->ip(), $whitelistedIps)) {
            return Response::json([
                "message" => "Unauthorized",
            ], 403);
        }
        $paymentConfigModel = Config::get('bukukwasiku.payment.model');
        try {
            if ($request->has('status')) {
                if ($request->status == "success") {
                    if (!$request->has('reference') || empty($request->reference)) {
                        return Response::json([
                            "success" => false,
                            "message" => "Reference is required.",
                        ], 400);
                    }
                    $payment = $paymentConfigModel::where('receipt', $request->payment_reference)->first();
                    if ($payment) {
                        return Response::json([
                            "success" => false,
                            "message" => "Payment has been utilized.",
                        ], 404);
                    }

                    $request->merge([
                        'reference' => str_replace('BKST2', '', $request->reference),
                    ]);
                    if ($commit) {
                        DB::beginTransaction();
                    }
                    $payment = $paymentConfigModel::whereId($request->reference)->first();
                    if (!$payment) {
                        return Response::json([
                            "success" => false,
                            "message" => "Reference Not found",
                        ], 404);
                    }
                    $payment->status        = $request->status;
                    $payment->receipt       = $request->payment_reference;
                    $payment->remarks       = $request->remarks;
                    $payment->save();
                    $this->sendPaymentToBukuKwaSiku($payment->id);
                    if ($commit) {
                        DB::commit();
                    }
                }
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());
            return Response::json([
                "success" => true,
                "message" => "Internal Server Error",
            ], 500);
        }
        return Response::json([
            "success" => false,
            "message" => "Payment Reference Not found",
        ], 404);
    }

    public function sendPaymentToBukuKwaSiku($paymentId)
    {
        $paymentConfigModel = Config::get('bukukwasiku.payment.model');
        $orderConfigModel = Config::get('bukukwasiku.order.model');
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        $payment = $paymentConfigModel::whereId($paymentId)->toBase()->first();
        $payable = $payment->payable;
        if ($payable instanceof $orderConfigModel) {
            OrderController::prepareRequestForBukukwasiku($payment);
        }
        $params = [];
        $references = [];
        if ($payment->references) {
            $references = json_decode($payment->references, true);
            foreach ($references as $reference) {
                $payment = (array) $payment;
                $payment['method_id'] = $payment['payment_method'];
                $product = $orderItemConfigModel::withoutGlobalScopes()->where('bkb_reference', $reference['reference'])->first();
                if ($product) {
                    $payment['payment'] = $payment;
                    $payment['amount'] = $reference['amount'];
                    $params[] = [
                        "payment" => $payment,
                        "amount" => $payment['amount'],
                        "reference" => $product->bkb_reference,
                    ];
                    $references[] = $product->bkb_reference;
                }
            }
        } else {
            $order = $orderConfigModel::withoutGlobalScopes()->whereId($payment->payable_id)->first();
            if ($order) {
                $orderItems = $order->orderItems;
                $payment = (array) $payment;
                $payment['method_id'] = $payment['payment_method'];
                foreach ($orderItems as $product) {
                    $params[] = [
                        "payment" => $payment,
                        "amount" => $payment['amount'],
                        "reference" => $product->bkb_reference
                    ];
                    $references[] = $product->bkb_reference;
                }
            } else {
                $product = $orderItemConfigModel::withoutGlobalScopes()->where('id', $payment->payable_id)->first();
                if ($product) {
                    $payment = (array) $payment;
                    $payment['method_id'] = $payment['payment_method'];
                    $params[] = [
                        "payment" => $payment,
                        "amount" => $payment['amount'],
                        "reference" => $product->bkb_reference,
                    ];
                    $references[] = $product->bkb_reference;
                }
            }
        }

        $client = new Client([
            'verify' => false,
        ]);

        $response = $client->request('POST', Config::get('bukukwasiku.bukukwasiku.save_payment'), [
            'form_params' => [
                "products" => $params,
                "references" => $references,
            ],
        ]);

        $resultData = $response->getBody()->getContents();
        if (!empty($resultData)) {
            $results = json_decode($resultData, TRUE);
            if (isset($results['success']) && $results['success'] === true) {
                return Response::json([
                    "success" => true,
                    "message" =>  $results['message'] ?? "",
                    "data" => $payment
                ]);
            } else {
                return Response::json([
                    "success" => false,
                    "message" =>  "Internal Server Error 1",
                ]);
            }
        } else {
            return Response::json([
                "success" => false,
                "message" =>  "Internal Server Error 2",
            ]);
        }
    }

    public function walletPing(Request $request)
    {
        $ips = explode(",", env("WALLET_WHITELISTED_IPS", []));
        Log::error("IP: " . $request->ip());
        if (!in_array($request->ip(), $ips)) {
            return Response::json([
                "message" => "Not Authorized",
            ]);
        }
        $paymentConfigModel = Config::get('bukukwasiku.payment.model');
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        $transactionConfigModel = Config::get('bukukwasiku.transaction.model');
        $smsService = Config::get('bukukwasiku.sms.service');
        $smsFunction = Config::get('bukukwasiku.sms.function');

        if (!empty($request->references) && is_array($request->references)) {
            foreach ($request->references as $reference) {
                $ref = $reference['reference'];
                $amount = $reference['amount'];

                $orderItem = $orderItemConfigModel::whereBkbReference($ref)->withoutGlobalScopes()->first();
                $vendorOrder = $orderItem->vendorOrder ?? null;
                $order = $vendorOrder->order ?? null;

                if ($order) {
                    $user = $order->ordered ?? null;
                    try {
                        $checkSufficientFn = Config::get('bukukwasiku.transaction.checkBalance');
                        if (method_exists(new $transactionConfigModel, $checkSufficientFn)) {
                            $transactionConfigModel::$checkSufficientFn($user, $amount);
                        }
                    } catch (Exception $e) {
                        if (method_exists(new $smsService, $smsFunction)) {
                            $smsService::$smsFunction($user->phone, "Oops! You don’t have enough funds to pay for order $ref. You need $amount to proceed.");
                        }
                        return Response::json([
                            'success' => false,
                            "message" => $e->getMessage(),
                        ]);
                    }
                    if ($user) {
                        DB::beginTransaction();
                        try {
                            $payment = $paymentConfigModel::create([
                                "type" => "wallet",
                                "payable_id" => $orderItem->id,
                                "payable_type" => $orderItemConfigModel::class,
                                "payment_method" => 3,
                                "reference" => $request->reference,
                                "msisdn" => $request->msisdn,
                                "amount" => $amount,
                                "references" => null,
                            ]);
                            $transaction = null;
                            $this->walletPayment($orderItem, $order, $payment, $transaction, $user);
                            DB::commit();
                        } catch (Exception $e) {
                            DB::rollBack();
                            return Response::json([
                                'success' => false,
                                "message" => $e->getMessage(),
                            ]);
                        }
                        $wallet = $transactionConfigModel::balance($user);
                        if (method_exists(new $smsService, $smsFunction)) {
                            $smsService::$smsFunction($user->phone, "Debited $amount from {$wallet->reference} for {$ref}, Txn: {$transaction->transid}. Not you? Call +255655922084 ASAP.");
                        }
                    }
                }
            }
        }
    }

    public function paymentNotify(Request $request)
    {
        $ips = explode(",", env("WALLET_WHITELISTED_IPS", []));
        Log::error("IP: " . $request->ip());
        if (!in_array($request->ip(), $ips)) {
            return Response::json([
                "message" => "Not Authorized",
            ]);
        }
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        $smsService = Config::get('bukukwasiku.sms.service');
        $smsFunction = Config::get('bukukwasiku.sms.function');

        if (!empty($request->references) && is_array($request->references)) {
            foreach ($request->references as $reference) {
                $ref = $reference['reference'];
                $amount = $reference['amount'];

                $orderItem = $orderItemConfigModel::whereBkbReference($ref)->withoutGlobalScopes()->first();
                $vendorOrder = $orderItem->vendorOrder ?? null;
                $order = $vendorOrder->order ?? null;

                if ($order) {
                    $user = $order->ordered ?? null;
                    if (method_exists(new $smsService, $smsFunction)) {
                        $smsService::$smsFunction($user->phone, "Your remainded to pay " . number_format($amount) . " for {$ref}");
                    }
                }
            }
        }
    }
}
