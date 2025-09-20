<?php

namespace BukukwasikuPackage\Presentation\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class OrderController extends Controller
{

    public function userOrders()
    {
        $categoryFilter = function ($category) {
            if (request()->has('saving')) {
                $category->where('slug', 'savings');
            } else if (request()->has('memberships')) {
                $category->where('slug', 'memberships');
            } else {
                $category->where('slug', '!=', 'savings')->where('slug', '!=', 'memberships');
            }
            return $category;
        };

        $orderModel = Config::get('bukukwasiku.order.model');
        $orderServingCheck = Config::get('bukukwasiku.order.check_savings');
        $orders = $orderModel::where('ordered_id', Auth::user()->id ?? null)
            ->when($orderServingCheck, function ($query) use ($categoryFilter) {
                return $query->with(['orderItems.product.category', 'payment'])
                    ->whereHas('orderItems', function ($orderItems) use ($categoryFilter) {
                        $orderItems->whereHas('product.category', $categoryFilter);
                    });
            })
            ->latest()
            ->take(20)
            ->get();

        return Response::json([
            'success' => true,
            'orders' => $orders,
        ], 200);
    }
    public function singleOrder($orderId)
    {
        $orderModel = Config::get('bukukwasiku.order.model');
        $singleOrder = $orderModel::where('id', $orderId)->with('orderItems');
        if (request()->has('saving')) {
            $singleOrder->with('orderSavingItems');
        }
        $singleOrder = $singleOrder->first();

        return Response::json([
            'success' => true,
            'order' => $singleOrder
        ]);
    }

    public function initiateOrderPayment($orderId)
    {
        $orderModel = Config::get('bukukwasiku.order.model');
        $paymentModel = Config::get('bukukwasiku.payment.model');
        $order = $orderModel::where('id', $orderId)->first();
        $payment = $paymentModel::where('payable_id', $order->id)->where('payable_type', $orderModel)->whereNull('receipt')->first();
        if (!$order) {
            return Response::json([
                'success' => false,
                'message' => "Order not Found",
            ]);
        }
        if (!$payment) {
            return Response::json([
                'success' => false,
                'message' => "Payment not Found Or Already Paid",
            ]);
        }
        return $this->makePayment(new Request, $order, $payment->id);
    }

    public function makeOrder(Request $request)
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

        $this->logError(message: "Make Payment Started");
        $validate = Validator::make($request->all(), [
            'orders' => 'required',
            'payment' => ['required', Rule::in(['cash', 'installment'])],
            "payment_type" => $request->payment == "installment" ? "" : ["required", Rule::in(['manual', 'online'])],
            "payment_method" => $request->payment == "installment" ? "" : ($request->payment_type == "manual" ? "" : "required"),
            "reference" => $request->payment == "installment" ? "" : ($request->payment_type == "manual" ? "required|string|unique:payments,reference" : ""),
            "msisdn" => $request->payment == "installment" ? "" : "required|string",
            "amount" => $request->payment == "installment" ? "" : "required|numeric",
        ]);
        if ($validate->fails()) {
            $this->logError(message: "Failed with " . $validate->errors()->first());
            return Response::json([
                "success" => false,
                "message" => $validate->errors()->first(),
                "errors" => $validate->errors()
            ], 422);
        }
        try {
            $this->logError(message: "Check Order details");
            if (is_string($request->orders)) {
                $request->merge([
                    'orders' => json_decode($request->orders, true)
                ]);
            }
        } catch (Exception $e) {
            $this->logError(message: $e->getMessage());
            return Response::json([
                "success" => false,
                "message" => $e->getMessage()
            ], 400);
        }

        $orderItems = [];
        try {
            DB::beginTransaction();
            if (is_array($request->orders)) {
                $this->logError(message: "Start ordering");
                $totalAmount = 0;
                $totalInitialDeposit = 0;
                foreach ($request->orders as $order) {
                    foreach ($order['vendor_products'] as $vendorProduct) {
                        $totalAmount += ($vendorProduct['product_price'] * $vendorProduct['quantity']);
                        $totalInitialDeposit += ($vendorProduct['initial_deposit'] * $vendorProduct['quantity']);
                    }
                }
                if ($totalInitialDeposit > 0) {
                    $request->merge(
                        [
                            'amount' => $totalInitialDeposit,
                        ]
                    );
                }

                // CHECK IF IS SUFFICIENT BALANCE WHEN SELECTED PAYMENT METHOD IS WALLET
                $this->logError(message: "CHECK IF IS SUFFICIENT BALANCE WHEN SELECTED PAYMENT METHOD IS WALLET");
                if ($request->payment_method == Config::get('bukukwasiku.transaction.nafuu_id')) { // is nafuu wallet
                    $transactionModel = Config::get('bukukwasiku.transaction.model');
                    $checkSufficientFn = Config::get('bukukwasiku.transaction.checkBalance');
                    try {
                        if (method_exists(new $transactionModel, $checkSufficientFn)) {
                            $transactionModel::$checkSufficientFn($request->amount);
                        }
                    } catch (Exception $e) {
                        DB::rollBack();
                        $this->logError(message: $e->getMessage());
                        return Response::json([
                            'success' => false,
                            "message" => $e->getMessage(),
                        ]);
                    }
                }
                $orderConfigModel = Config::get('bukukwasiku.order.model');
                $orderModel = $orderConfigModel::create(
                    [
                        'ordered_id' => Auth::user()->id,
                        'ordered_type' => get_class(Auth::user()),
                        'amount' => $totalAmount,
                        'status' => 'draft'
                    ]
                );
                if ($orderModel->wasRecentlyCreated) {
                    $this->logError(message: "Order created with id {$orderModel->id}");
                    $vendorConfigModel = Config::get('bukukwasiku.vendor.model');
                    $productConfigModel = Config::get('bukukwasiku.product.model');
                    $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
                    $vendorOrderConfigModel = Config::get('bukukwasiku.vendor_order.model');
                    foreach ($request->orders as $order) {
                        $vendorId = $order['vendor_id'];
                        $vendor = $vendorConfigModel::whereId($vendorId)->first();
                        if (!$vendor) {
                            DB::rollBack();
                            $this->logError(message: "Vendor with id" . $vendor . "Not found!");
                            return Response::json([
                                "success" => false,
                                "message" => "Vendor with id" . $vendor . "Not found!",
                            ], 404);
                        }

                        $totalVendorAmount = 0;
                        foreach ($order['vendor_products'] as $vendorProduct) {
                            $totalVendorAmount += ($vendorProduct['product_price'] * $vendorProduct['quantity']);
                        }

                        $vendorOrder = $vendorOrderConfigModel::create([
                            "vendor_id" => $vendorId,
                            'amount' => $totalVendorAmount,
                            'order_id' => $orderModel->id,
                            'status' => 'pending'
                        ]);

                        if ($vendorOrder->wasRecentlyCreated) {
                            foreach ($order['vendor_products'] as $vendorProduct) {
                                $product = $productConfigModel::withoutGlobalScopes()->with('attachments')->whereId($vendorProduct['product_id'])->first();
                                if (!$product) {
                                    DB::rollBack();
                                    $this->logError(message: "Product with id" . $vendorProduct['product_id'] . " Not found!");
                                    return Response::json([
                                        "success" => false,
                                        "message" => "Product with id" . $vendorProduct['product_id'] . " Not found!",
                                    ], 404);
                                }

                                $category = $product->category;
                                $vendorProductInitialDeposit = ($vendorProduct['initial_deposit'] * $vendorProduct['quantity']);
                                if (($category->slug ?? '') != "savings") {
                                    $opening_amount = 0;
                                } else {
                                    $opening_amount = $vendorProduct['opening_amount'] ?? $vendorProductInitialDeposit;
                                }
                                $ncard = $vendorProduct['n_card'] ?? null;
                                $orderItem = $orderItemConfigModel::create([
                                    'vendor_order_id' => $vendorOrder->id,
                                    'product_id' => $vendorProduct['product_id'],
                                    'quantity' => $vendorProduct['quantity'],
                                    'price_name' => $vendorProduct['price_name'] ?? null,
                                    'n_card' => $ncard,
                                    'vendor_id' => $vendorId,
                                    'amount_per_day' => $vendorProduct['amount_per_day'] ?? 1000,
                                    'price' => $vendorProduct['product_price'],
                                    'frequency' => $vendorProduct['frequency'],
                                    'initial_deposit' => $vendorProductInitialDeposit,
                                    'opening_amount' => (($category->slug ?? '')  == 'savings') ? $opening_amount : 0,
                                ]);

                                $this->logError(message: "Order Item created with id {$orderItem->id}");
                                // MAKE PAYMENT IF IS WALLET
                                $payment = null;
                                $updatePaid = false;
                                $sendPayment = true;
                                $transaction = true;
                                if ($request->payment_method == 3) {
                                    try {
                                        $payment = static::walletPayment($orderItem, $orderModel, $request->amount);
                                        $updatePaid = true;
                                        $sendPayment = false;
                                        $transaction = true;
                                    } catch (Exception $e) {
                                        DB::rollBack();
                                        $this->logError(message: "Failed with: " . $e->getMessage());
                                        return Response::json([
                                            'success' => false,
                                            "message" => $e->getMessage(),
                                        ]);
                                    }
                                }
                                static::orderItemRequest($orderItem, $orderModel, $payment, $orderItems, $sendPayment, $transaction, $updatePaid, $vendorProductInitialDeposit);
                                $this->logError(message: "Failed with: " . json_encode($orderItems));
                            }
                        } else {
                            $this->logError(message: "Failed with: Failed to create order");
                            DB::rollBack();
                            return Response::json([
                                "success" => false,
                                "message" => "Failed to create order",
                            ], 500);
                        }
                    }
                } else {
                    $this->logError(message: "Failed with: Failed to create order 2");
                    DB::rollBack();
                    return Response::json([
                        "success" => false,
                        "message" => "Failed to create order",

                    ], 500);
                }
            } else {
                $this->logError(message: "Failed with: Failed to create order 2");
                return Response::json([
                    "success" => false,
                    "message" => "Orders must be an array",
                ], 400);
            }


            if (request()->filled("payment")) {
                if ($request->payment_method == 3) {
                    $response = static::installmentBukuKwaSiku($orderItems);
                    $this->logError(message: "Instalments => " . json_encode($orderItems));
                } else if (request()->input('payment') == "installment") {
                    if ($request->amount > 0) {
                        $response = $this->makePayment($request, $orderModel);
                    } else {
                        $response = static::installmentBukuKwaSiku($orderItems);
                        $this->logError(message: "Instalments => " . json_encode($orderItems));
                    }
                } else {
                    $response = $this->makePayment($request, $orderModel);
                }
            }
            DB::commit();
            return $response;
            return Response::json([
                "success" => true,
                "message" => "Order created successfully",
            ], 200);
        } catch (Exception  $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());
            $this->logError(message: "getMessage " . $e->getMessage());
            return Response::json([
                "success" => false,
                "message" => "Internal server error!",
            ], 500);
        }
    }

    public static function walletPayment($orderItem, $order, $amount)
    {
        $user = Auth::user();
        $transactionModel = Config::get('bukukwasiku.transaction.model');
        $transaction = $transactionModel::transact($user, $amount, $transactionModel::USE, "Payment of Order: " . $order->reference, $orderItem, false);
        return [
            "reference" => $transaction->transid,
            "amount" => $transaction->amount,
            "status" => "Success",
            "msisdn" => $user->phone,
            "receipt" => $transaction->transid,
            "remarks" => "FROM WALLET " . "Payment of Order: " . $order->reference,
            "payable_type" => get_class($transaction),
            "payable_id" => $transaction->id,
            "payment_method" => 3
        ];
    }

    public function makePayment(Request $request, $order, $paymentId = null)
    {
        DB::beginTransaction();
        $paymentConfigModel = Config::get('bukukwasiku.payment.model');
        if ($paymentId) {
            $payment = $paymentConfigModel::whereId($paymentId)->first();
            Request::merge([
                'payment_type' => 'online'
            ]);
        } else {
            if ($request->amount < 100) {
                $this->logError(message: "Amount must not be less than 100 TZS");
                return Response::json(([
                    "success" => false,
                    "message" => "Amount must not be less than 100 TZS"
                ]), 401);
            }
            $payment = $paymentConfigModel::create([
                'type' => $request->payment_type,
                "reference" => $request->reference,
                "receipt" => $request->reference,
                "payable_type" => get_class($order),
                "payable_id" => $order->id,
                "msisdn" => $request->msisdn,
                "amount" => $request->amount,
                "payment_method" => $request->payment_type == "manual" ? 4 : $request->payment_method,
                "status" => 'pending'
            ]);
            $this->logError(message: "Payment created with $payment->id");
        }
        if (!$payment) {
            DB::rollBack();
            $this->logError(message: "Failed to create payment");
            return Response::json(([
                "success" => false,
                "message" => "Failed to create payment"
            ]), 500);
        } else {
            if ($request->payment_type == "manual") {
                DB::commit();
                $this->logError(message: "Payment Received waiting for approval");
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
                    'reference' => Config::get('bukukwasiku.payment.initial') . $payment->id,
                    "msisdn" => $payment->msisdn,
                    "amount" => in_array($payment->msisdn, $whitelistedPhones) ? 100 : $payment->amount,
                    "network" => $payment->payment_method,
                    "callback" => Config::get('bukukwasiku.payment.order_payment_url'),
                ];
                $this->logError(message: "Payment callback " . Config::get('bukukwasiku.payment.payment_callback'));
                $response = $client->request('POST', Config::get('bukukwasiku.payment.push_payment_url'), [
                    'form_params' => $data,
                ]);

                $resultData = $response->getBody()->getContents();
                if (!empty($resultData)) {
                    $results = json_decode($resultData, TRUE);
                    Log::info($results);
                    if (isset($results['success']) && $results['success'] === true) {
                        DB::commit();
                        $this->logError(message: "Payment return " . $results['message'] ?? "");
                        return Response::json([
                            "success" => true,
                            "message" =>  $results['message'] ?? "",
                            "data" => $payment
                        ]);
                    } else {
                        DB::rollBack();
                        $this->logError(message: "Payment return Internal Server Error 1");
                        return Response::json([
                            "success" => false,
                            "message" =>  "Internal Server Error 1",
                        ], 500);
                    }
                } else {
                    DB::rollBack();
                    $this->logError(message: "Payment return Internal Server Error 2");
                    return Response::json([
                        "success" => false,
                        "message" =>  "Internal Server Error 2",
                    ], 500);
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e->getMessage());
                Log::error($e->getTraceAsString());
                $this->logError(message: "Payment return " . $e->getMessage());
                return Response::json([
                    "success" => false,
                    "message" =>  $e->getMessage(),
                ]);
            }
        }
    }

    public function recheckOrder($orderId)
    {
        $orderConfigModel = Config::get('bukukwasiku.order.model');
        $paymentConfigModel = Config::get('bukukwasiku.payment.model');
        $order = $orderConfigModel::where('id', $orderId)->first();
        $payment = $paymentConfigModel::where('payable_id', $order->id)->where('payable_type', $orderConfigModel::class)->whereNull('receipt')->first();
        if (!$order) {
            return Response::json([
                'success' => false,
                'message' => "Order not Found",
            ]);
        }
        if (!$payment) {
            return Response::json([
                'success' => false,
                'message' => "Payment not Found Or Already Paid",
            ]);
        }
        $client = new Client([
            'verify' => false,
        ]);
        try {
            $data = [
                'reference' => Config::get('bukukwasiku.payment.initial') . $payment->id,
            ];

            $response = $client->request('POST', Config::get('bukukwasiku.payment.verification'), [
                'form_params' => $data,
            ]);

            $resultData = $response->getBody()->getContents();
            if (!empty($resultData)) {
                $results = json_decode($resultData, TRUE);
                Log::info($results);
                if (isset($results['success']) && $results['success'] === true) {
                    DB::commit();
                    $results['success'] = $results['success'] ? "success" : "failed";
                    $results['reference'] = Config::get('bukukwasiku.payment.initial') . $payment->id;
                    Request::merge($results);
                    return $this->orderPaymentCallback(new Request());
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
            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());
            return Response::json([
                "success" => false,
                "message" =>  $e->getMessage(),
            ]);
        }
    }

    public function orderPaymentCallback(Request  $request)
    {
        $whitelistedIps = explode(',', env("WHITELISTED_IPS"));
        Log::error("IP: " . $request->ip());
        if (!in_array($request->ip(), $whitelistedIps)) {
            Log::error("Unauthorized " . $request->ip());
            return Response::json([
                "message" => "Unauthorized",
                "api" => $request->ip(),
            ], 403);
        }
        try {
            DB::beginTransaction();
            if ($request->has('status')) {
                $paymentConfigModel = Config::get('bukukwasiku.payment.model');
                if ($request->status == "success") {
                    if (!$request->has('reference') || empty($request->reference)) {
                        Log::error("Reference is required ");
                        return Response::json([
                            "success" => false,
                            "message" => "Reference is required.",
                        ], 400);
                    }
                    $payment = $paymentConfigModel::where('receipt', $request->payment_reference)->first();
                    if ($payment) {
                        Log::error("Payment has been utilized.");
                        return Response::json([
                            "success" => false,
                            "message" => "Payment has been utilized.",
                        ], 404);
                    }

                    $reference = str_replace(Config::get('bukukwasiku.payment.initial'), '', $request->reference);
                    $payment = $paymentConfigModel::whereId($reference)->first();
                    if (!$payment) {
                        Log::error("Reference Not found $reference");
                        return Response::json([
                            "success" => false,
                            "message" => "Reference Not found",
                        ], 404);
                    }
                    $payment->status        = $request->status;
                    $payment->receipt       = $request->payment_reference;
                    $payment->remarks       = $request->remarks;
                    $payment->save();

                    static::prepareRequestForBukukwasiku($payment);

                    Log::error("Payment Succefully");
                    return Response::json([
                        "success" => true,
                        "message" => "Payment Succefully",
                    ], 200);
                }
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            Log::error($e->getTraceAsString());
            return Response::json([
                "success" => false,
                "message" => "Internal Server Error",
            ], 500);
        }
        Log::error("Payment Reference Not found");
        return Response::json([
            "success" => false,
            "message" => "Payment Reference Not found",
        ], 404);
    }

    public static function prepareRequestForBukukwasiku($payment)
    {
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        $order = $payment->payable;
        if ($order) {
            $paidAmount = $payment->amount;
            if ($paidAmount > 0) {
                $orderItems = $orderItemConfigModel::withoutGlobalScopes()->with("vendorOrder")->whereHas("vendorOrder", function ($vo) use ($order) {
                    return $vo->whereOrderId($order->id);
                })->orderBy('price', 'ASC')->get();

                $orderItemRequests = [];
                foreach ($orderItems as $orderItem) {
                    static::orderItemRequest($orderItem, $order, $payment, $orderItemRequests, true, false, true, $orderItem->initial_deposit);
                }
                static::installmentBukuKwaSiku($orderItemRequests);
            }
            static::updateOrderStatus($order);
        }
    }


    public static function orderItemRequest($orderItem, $order, $payment, &$orderItemRequests, $sendPayment = true, $transaction = false, $updatePaid = false, $paymentAmount = 0)
    {
        $product = $orderItem->product;

        $totalAmount = ($orderItem->price * $orderItem->quantity);
        $orderItemAmount = $totalAmount - $orderItem->paid_amount;

        if ($updatePaid) {
            $orderItem->paid_amount += ($paymentAmount - $orderItem->opening_amount);
        }
        $orderItem->outstanding_amount = ($orderItem->price * $orderItem->quantity) - $orderItem->paid_amount;

        $orderItem->no_of_installment = $orderItem->amount_per_day > 0 ? ($totalAmount / $orderItem->amount_per_day) : 1;
        $orderItem->no_of_paid_installment = $orderItem->amount_per_day > 0 ? ($orderItem->paid_amount / $orderItem->amount_per_day) : 1;
        $orderItem->no_of_unpaid_installment = $orderItem->no_of_installment - $orderItem->no_of_paid_installment;
        $orderItem->total_paid_amount = $orderItem->paid_amount ?? 0;

        $orderItem->due_days = 0;
        $orderItem->due_amount = 0;
        $orderItem->amount_to_pay = $orderItem->amount_per_day;
        $orderItem->save();

        $vendor = $orderItem->vendor;
        $orderer = $order->ordered;

        if ($orderItemAmount > 0) {
            $paymentRecord = null;
            if (!$transaction) {
                $paymentConfigModel = Config::get('bukukwasiku.payment.model');
                $paymentRecord = $paymentConfigModel::whereId($payment->id)->toBase()->first();
                if ($paymentRecord) {
                    $paymentRecord = collect((array) $paymentRecord)->toArray();
                    $paymentRecord['payment'] = $paymentRecord; // Store original safely
                    $paymentRecord['amount'] = $paymentAmount;
                    $paymentRecord['remarks'] = $orderItem->opening_amount > 0 ? "account_opening" : $paymentRecord['remarks'];
                }
            }

            $payment = is_object($payment) ? (array) $payment : $payment;
            $orderItemRequests[] = [
                ...static::companyRequest($vendor),
                ...static::customerRequest($orderer),
                ...static::productRequest($product, $orderItem->price, $orderItem->quantity, $orderItem),
                ...[
                    'payment' => [
                        'no_of_installment' => $orderItem->no_of_installment,
                        'no_of_paid_installment' => $orderItem->no_of_paid_installment,
                        'total_paid_amount' => $orderItem->paid_amount,
                        'frequency' => $orderItem->frequency,
                        'amount_per_day' => $orderItem->amount_per_day,
                        'start_payment_date' => Carbon::now()->addDay()->format('Y-m-d'),
                        'next_payment_date' =>  Carbon::now()->addDay()->addHour()->format('Y-m-d'),
                        'payment_callback' => Config::get('bukukwasiku.payment.payment_callback'),
                        'notification_callback' => Config::get('bukukwasiku.payment.notification_callback'),

                        "payment_type" => $payment['type'] ?? null,
                        "reference" => $payment['reference'] ?? null,
                        "msisdn" => $payment['msisdn'] ?? null,
                        "payment_method" => $payment['payment_method'] ?? null,

                        "payment" => $sendPayment ? $paymentRecord : ($transaction ? $payment : null),
                    ]
                ]
            ];
        }
    }


    public static function installmentBukuKwaSiku($orderItems)
    {
        if (!count($orderItems)) {
            return Response::json([
                "success" => false,
                "message" => "No order items",
            ], 200);
        }
        $http = new Client([
            'verify' => false,
        ]);
        $response = $http->request('POST', Config::get('bukukwasiku.bukukwasiku.save_product'), [
            'form_params' => [
                "products" => $orderItems
            ],
        ]);
        $resultData = $response->getBody()->getContents();
        return static::updateRefenceInfos($resultData);
    }

    public static function updateRefenceInfos($resultData)
    {
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        if (!empty($resultData)) {
            $results = json_decode($resultData, TRUE);
            if (isset($results['success']) && $results['success'] === true) {
                $products = $results['products'];
                foreach ($products as $product) {
                    static::sLogError(json_encode($product));
                    $orderItem = $orderItemConfigModel::withoutGlobalScopes()->whereId($product['product_id']);
                    static::sLogError(json_encode($orderItem->first()),title: "BUKU BEFORE UPDATE");
                    if ($orderItem->count()) {
                        $orderItem->update([
                            'bkb_reference' => $product['reference'],
                            'paid_amount' => $product['schedule']['total_paid_amount'] ?? 0,
                            'no_of_installment' => $product['schedule']['no_of_installment'] ?? 0,
                            'no_of_paid_installment' => $product['schedule']['no_of_paid_installment'] ?? 0,
                            'no_of_unpaid_installment' => $product['schedule']['no_of_unpaid_installment'] ?? 0,
                            'amount_per_day' => $product['schedule']['amount_per_day'] ?? 0,
                            'total_paid_amount' => $product['schedule']['total_paid_amount'] ?? 0,
                            'next_payment_date' => $product['schedule']['next_payment_date'] ?? 0,
                            'start_payment_date' => $product['schedule']['start_payment_date'] ?? 0,
                            'outstanding_amount' => $product['schedule']['outstanding_amount'] ?? 0,
                            'due_days' => $product['schedule']['due_days'] ?? 0,
                            'due_amount' => $product['schedule']['due_amount'] ?? 0,
                            'amount_to_pay' => $product['schedule']['amount_to_pay'] ?? 0,
                            'push_amount' => $product['schedule']['push_amount'] ?? 0,
                        ]);

                        static::sLogError(json_encode([
                            'bkb_reference' => $product['reference'],
                            'paid_amount' => $product['schedule']['total_paid_amount'] ?? 0,
                            'no_of_installment' => $product['schedule']['no_of_installment'] ?? 0,
                            'no_of_paid_installment' => $product['schedule']['no_of_paid_installment'] ?? 0,
                            'no_of_unpaid_installment' => $product['schedule']['no_of_unpaid_installment'] ?? 0,
                            'amount_per_day' => $product['schedule']['amount_per_day'] ?? 0,
                            'total_paid_amount' => $product['schedule']['total_paid_amount'] ?? 0,
                            'next_payment_date' => $product['schedule']['next_payment_date'] ?? 0,
                            'start_payment_date' => $product['schedule']['start_payment_date'] ?? 0,
                            'outstanding_amount' => $product['schedule']['outstanding_amount'] ?? 0,
                            'due_days' => $product['schedule']['due_days'] ?? 0,
                            'due_amount' => $product['schedule']['due_amount'] ?? 0,
                            'amount_to_pay' => $product['schedule']['amount_to_pay'] ?? 0,
                            'push_amount' => $product['schedule']['push_amount'] ?? 0,
                        ]), title: "BUKU UPDATE DATA");
                    }
                    $orderItem = $orderItem->first();
                    static::sLogError(json_encode($orderItem), title: "BUKU AFTER UPDATE");
                    $vendorOrder = $orderItem->vendorOrder ?? null;
                    $vendor = $orderItem->vendor ?? null;
                    if ($vendorOrder) {
                        $order = $vendorOrder->order;
                        if ($order) {
                            $ordered = $order->ordered;
                            if ($ordered) {
                                $ordered->update([
                                    'bkb_reference' => $product['customer']['reference']
                                ]);
                            }
                            static::updateOrderStatus($order);
                        }
                    }
                    if ($vendor) {
                        $vendor->update([
                            'bkb_reference' => $product['company']['reference']
                        ]);
                    }
                }
                return Response::json([
                    "success" => true,
                    "message" => "Succefully",
                ], 200);
            } else {
                return Response::json([
                    "success" => false,
                    "message" =>  "Internal Server Error",
                ]);
            }
        } else {
            return Response::json([
                "success" => false,
                "message" =>  "Internal Server Error",
            ]);
        }
    }

    public static function updateOrderStatus($order)
    {
        if ($order) {
            $total_paid_amount = $order->orderItems->sum('total_paid_amount');
            $outstanding_amount = $order->orderItems->sum('outstanding_amount');
            $status = $total_paid_amount > 0 ? 'on progress' : 'pending';
            if ($outstanding_amount <= 0) {
                $status = 'paid';
            }
            $orderConfigModel = Config::get('bukukwasiku.order.model');
            $productCategoryConfigModel = Config::get('bukukwasiku.product_category.model');
            $orderConfigModel::whereId($order->id)->update([
                'status' => $status
            ]);

            $order = $orderConfigModel::whereId($order->id)->first();
            if ($order->status == "paid") {
                foreach ($order->orderItems as $orderItem) {
                    $category = $productCategoryConfigModel::whereId($orderItem->product->category_id ?? null)->first();
                    if (($category->slug ?? "") == Config::get('bukukwasiku.product_category.membership_slug')) {
                        if ($order->orderItems()->count() > 1) {
                            break;
                        }
                        static::registerMembership($orderItem, $order);
                    }
                }
            }
            $walletConfigModel = Config::get('bukukwasiku.wallet.model');
            $walletConfigModel::updateBalance($order->ordered);
            DB::commit();
        }
    }

    public static function  registerMembership($orderItem, $order)
    {
        $memberDetailConfigModel = Config::get('bukukwasiku.member_detail.model');
        $orderItemConfigModel = Config::get('bukukwasiku.order_item.model');
        $orderConfigModel = Config::get('bukukwasiku.order.model');
        $paymentConfigModel = Config::get('bukukwasiku.payment.model');
        $memberDetailConfigPrefix = Config::get('bukukwasiku.member_detail.prefix');
        $checKMembershipOrder = $memberDetailConfigModel::where('order_item_id', $orderItem->id)->first();
        if (!$checKMembershipOrder) {
            $membershipAmount = $orderItem->product->membership_fee ?? 120000;
            $membership = $memberDetailConfigModel::create([
                'name' => $orderItem->product->name,
                'member_id' => $memberDetailConfigPrefix . Carbon::now()->timestamp . strtoupper(Str::random(3)),
                'price' => $membershipAmount,
                'order_item_id' => $orderItem->id
            ]);

            $product = $orderItem->product;

            $orderItem = $orderItemConfigModel::create([
                'vendor_order_id' => $orderItem->vendor_order_id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price_name' => $product->name ?? null,
                'n_card' => null,
                'vendor_id' => $orderItem->vendor_id,
                'amount_per_day' => $product->amount_per_day ?? 1000,
                'price' => $membership->price,
                'frequency' => $product->frequency ?? "Daily",
                'initial_deposit' => 0,
                'opening_amount' => 0,
            ]);


            static::updateOrderStatus($order);
            $payment = $paymentConfigModel::where('payable_id', $order->id)->where('payable_type', $orderConfigModel)->toBase()->first();
            $orderItems = [];
            $updatePaid = false;
            $sendPayment = false;
            $transaction = true;
            static::orderItemRequest($orderItem, $order, $payment, $orderItems, $sendPayment, $transaction, $updatePaid, $membershipAmount);
            static::installmentBukuKwaSiku($orderItems);
        }
    }

    public static function companyRequest($vendor)
    {
        if ($vendor->bkb_reference) {
            return [
                'company' => [
                    'reference' => $vendor->bkb_reference
                ]
            ];
        }
        return [
            'company' => [
                'name' => $vendor->name,
                'phone' => $vendor->contact_person_phone,
                'email' => $vendor->contact_person_email,
            ]
        ];
    }

    public static function customerRequest($customer)
    {
        if ($customer->bkb_reference) {
            return [
                'customer' => [
                    'reference' => $customer->bkb_reference,
                ]
            ];
        }
        return [
            'customer' => [
                'name' => $customer->name ?? $customer->phone,
                'phone' => $customer->phone,
                'email' => $customer->email ?? ($customer->phone . '@bukukwasiku.co.tz'),
                'nationalId' => null,
                'customer_id' => $customer->id,
            ]
        ];
    }

    public static function productRequest($product, $price, $quantity, $orderItem)
    {
        return [
            'product' => [
                'product_id' => $orderItem->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => $price,
                'quantity' => $quantity,
                'currency' => 'TZS',
                'images' => json_encode($product->attachments->pluck('attachment')->toArray()),
            ]
        ];
    }

    public function paymentCallback(Request $request)
    {
        $http = new Client([
            'verify' => false,
        ]);
        $reference  = $request->reference;
        $response = $http->request('POST', "https://bukukwasiku.bluetick.co.tz/api/product/$reference/status", [
            'form_params' => [],
        ]);
        $resultData = $response->getBody()->getContents();
        return static::updateRefenceInfos($resultData);
    }

    public function notificationCallback(Request $request)
    {
        Log::error($request->all());
    }
}
