<?php

use BukukwasikuPackage\Infrastructure\Eloquent\Models\MemberDetails;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Order;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\OrderItem;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Payment;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Product;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\ProductCategory;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Scopes\VendorScope;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Vendor;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\VendorOrder;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Wallet;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Wallet\NafuuWallet;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\Wallet\Transaction;
use BukukwasikuPackage\Infrastructure\Eloquent\Models\WalletWithdraw;
use BukukwasikuPackage\Presentation\Services\SmsService;

return [

    "bukukwasiku" => [
        "save_product" => "https://bukukwasiku.bluetick.co.tz/api/product/create",
        "save_payment" => "https://bukukwasiku.bluetick.co.tz/api/payment/save",
    ],

    "sms" => [
        "service" => SmsService::class,
        "function" => "sendSms",
        "sms" => "An amount of :amount has been debited from account :bkb_reference charges are :fee. If this was not authorized, please call +255655922084 immediately.",
        "params" => [
            'sender' => "BLUETICK",
            'token' => '145142365315gsafdg'
        ],
        "link" => "https://shushatanga.smartpay.co.tz/api/v1/send-sms",
        "headers" => [
            'accept' => 'application/json',
        ],
    ],

    "vendor_scope" => VendorScope::class,

    "transaction" => [
        "table" => "transactions",
        "nafuu_id" => 3,
        "checkBalance" => "checkSufficient",
        "model" => Transaction::class,
        "foreign_key" => "transaction_id",
    ],

    "product_category" => [
        "table" => "product_categories",
        "model" => ProductCategory::class,
        "foreign_key" => "wallet_id",
        "membership_slug" => "memberships",
    ],

    "nafuu_wallet" => [
        "table" => "nafuu_wallets",
        "checkSavingCharges" => "checkSavingCharges",
        "model" => NafuuWallet::class,
        "foreign_key" => "nafuu_wallet_id",
    ],

    "product" => [
        "table" => "products",
        "charge_function" => "checkCharges",
        "model" => Product::class,
        "foreign_key" => "product_id",
        "can_update_status" => true,
        "order_with" => ['team', 'teamAway', 'stadium', 'category'],
    ],

    "wallet" => [
        "table" => "wallets",
        "model" => Wallet::class,
        "foreign_key" => "wallet_id",
    ],

    "member_detail" => [
        "table" => "member_details",
        "model" => MemberDetails::class,
        "foreign_key" => "member_detail_id",
        "prefix" => "FMB",
    ],

    "wallet_withdraw" => [
        "table" => "wallet_withdraws",
        "model" => WalletWithdraw::class,
        "foreign_key" => "wallet_withdraw_id",
    ],

    "vendor" => [
        "table" => "vendors",
        "model" => Vendor::class,
        "foreign_key" => "vendor_id",
    ],

    "payment" => [
        "table" => "payments",
        "model" => Payment::class,
        "foreign_key" => "payment_id",

        "type" =>  "string",

        "minimum_amount" => 100,

        "push_payment_url" => 'https://payment.bluetick.co.tz/api/v1/push-payment-request',
        "verification" => "https://payment.bluetick.co.tz/api/payment-verification",

        "base_url" => "http://121.0.0.7:8000/api/",

        "payment_url" => env('APP_API_BASE_URL')  . "nafuu/wallet/deposit/callback",
        "order_payment_url" => env('APP_API_BASE_URL')  . "order-payment-callback",
//
        "payment_callback" => env('APP_API_BASE_URL')  . "payment-callback",
        "notification_callback" =>env('APP_API_BASE_URL')  . "notification-callback",

        "bukukwasiku_payment_callback" => env('APP_API_BASE_URL')  . "product/payment/bukukwasiku/verify",

        "initial" => "FNO",
    ],

    "order" => [
        "table" => "orders",
        "model" => Order::class,
        "foreign_key" => "order_id",
        "savings" => "orderSavingItems",
        "check_savings" => true,
        "reference" => [
            "prefix" => "BKSO",
            "lenght" => 10,
        ]
    ],

    "order_item" => [
        "table" => "order_items",
        "model" => OrderItem::class,
        "foreign_key" => "order_item_id",
        "reference" => [
            "prefix" => "BKSOI",
            "lenght" => 10,
        ]
    ],

    "vendor_order" => [
        "table" => "vendor_orders",
        "model" => VendorOrder::class,
        "foreign_key" => "vendor_order_id",
        "reference" => [
            "prefix" => "BKSVO",
            "lenght" => 10,
        ],
        "order_items_with" => [
            "product",
            "vendor"
        ]
    ],
];
