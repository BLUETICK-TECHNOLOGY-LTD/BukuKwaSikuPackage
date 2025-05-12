<?php

use BukukwasikuPackage\Presentation\Http\Controllers\BukuKwaSikuPayment;
use BukukwasikuPackage\Presentation\Http\Controllers\NafuuWalletController;
use BukukwasikuPackage\Presentation\Http\Controllers\OrderController;
use BukukwasikuPackage\Presentation\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;


Route::middleware("auth:sanctum")->group(function () {
    Route::post('/wallet/balance', [WalletController::class, 'balance'])->name(("api.wallet.balance"));
    Route::post('/wallet/withdraw', [WalletController::class, 'withdraw'])->name(("api.wallet.balance"));

    Route::get('/orders', [OrderController::class, 'userOrders'])->name(("api.orders"));
    Route::get('/single-order/{order}', [OrderController::class, 'singleOrder'])->name(("api.single-order"));
    Route::post('/order', [OrderController::class, 'makeOrder'])->name("api.make.order");
    Route::post('/recheck_payment/{order}', [OrderController::class, 'recheckOrder'])->name("api.make.recheckOrder");
    Route::post('/initiate_order_payment/{order}', [OrderController::class, 'initiateOrderPayment'])->name("api.make.initiateOrderPayment");

    Route::post('/nafuu/wallet/deposit', [NafuuWalletController::class, 'depositWallet'])->name("api.make.depositWallet");
    Route::post('/nafuu/wallet/balance', [NafuuWalletController::class, 'balance'])->name("api.make.depositWallet");
    Route::post('/nafuu/wallet/withdraw', [NafuuWalletController::class, 'withdraw'])->name("api.make.withdrawWallet");
    // 
    Route::post("product/payment/{payment?}", [BukuKwaSikuPayment::class, "makePayment"])->name("api.product.makePayment");
    // Route::post("update/user/kyc", [KycController::class, "updateKyc"])->name("api.updateKyc");
});

Route::post("product/payment/bukukwasiku/verify", [BukuKwaSikuPayment::class, "verify"])->name("api.bukukwasiku.payment.verify");

// Route::post('/check-n-card', [CheckNCarController::class, 'checkNCard'])->name("api.checkNCard");
Route::post('/payment-callback', [OrderController::class, 'paymentCallback'])->name("api.payment-callback");
Route::post('/order-payment-callback', [OrderController::class, 'orderPaymentCallback'])->name("api.order-payment-callback");

Route::post('/v1/wallet-payment-ping', [BukuKwaSikuPayment::class, 'walletPing'])->name("api.wallet-payment-ping");
Route::post('/v1/sms-payment-notify', [BukuKwaSikuPayment::class, 'paymentNotify'])->name("api.sms-payment-notify");

Route::post('/notification-callback', [OrderController::class, 'notificationCallback'])->name("api.notification-callback");
Route::post('/nafuu/wallet/deposit/callback', [NafuuWalletController::class, 'verify'])->name("api.wallet.deposit.verify");
