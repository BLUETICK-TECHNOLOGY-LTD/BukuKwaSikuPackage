<?php

namespace BukukwasikuPackage\Presentation\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{

    public function balance()
    {
        $user = Auth::user();
        $user->kyc;
        return Response::json([
            "user" => $user,
            "wallet" => $user->wallet,
        ]);
    }

    public function withdraw(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "amount" => "numeric"
        ]);
        if ($validator->fails()) {
            return Response::json([
                "success" => false,
                "message" => $validator->errors()->first(),
                "errors" => $validator->errors(),
            ]);
        }
        $user = Auth::user();
        try {
            DB::beginTransaction();
            $orderItemModel = Config::get('bukukwasiku.order_item.model');
            $orderItem = $orderItemModel::withoutGlobalScopes()->with('vendorOrder')->whereHas('vendorOrder', function ($vo) use ($user) {
                return $vo->with('order')->whereHas('order', function ($order) use ($user) {
                    return $order->where('ordered_id', $user->id)->where('ordered_type', (get_class($user)));
                });
            })->where('bkb_reference', $request->reference)->first();
            if (!$orderItem) {
                DB::rollBack();
                return Response::json([
                    "success" => false,
                    "message" => "Saving not found",
                ], 200);
            }
            $withdrawAmount = $request->amount;

            try {
                $productModel = Config::get('bukukwasiku.product.model');
                $chargeFunction = Config::get('bukukwasiku.product.charge_function');
                if (method_exists(new $productModel, $chargeFunction)) {
                    $fee = $productModel::$chargeFunction($withdrawAmount);
                }
            } catch (Exception $e) {
                return Response::json([
                    "success" => false,
                    "message" => $e->getMessage(),
                ], 200);
            }


            $remainAmount = ($orderItem->paid_amount - $orderItem->withdrawn_amount);
            $remainingAfterWithdraw = $remainAmount - ($withdrawAmount + $fee);
            if ($remainingAfterWithdraw < 100) {
                DB::rollBack();
                return Response::json([
                    "success" => false,
                    "message" => "Can't withdraw more than $remainAmount",
                ], 200);
            }

            $orderItem->withdrawn_at = Carbon::now()->format('Y-m-d H:i:s');
            $orderItem->withdrawn_amount += $withdrawAmount;
            $orderItem->charge_amount += $fee;
            $orderItem->save();

            $walletWithdrawModel = Config::get('bukukwasiku.wallet_withdraw.model');
            $walletWithdrawModel::create([
                "withdrawable_type" => get_class($orderItem),
                "withdrawable_id" => $orderItem->id,
                "amount" => $withdrawAmount,
                "fee" => $fee,
            ]);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return Response::json([
                "success" => false,
                "message" => "Sorry, error occurred please try again later",
            ], 200);
        }
        $smsService = Config::get('bukukwasiku.sms.service');
        $smsFunction = Config::get('bukukwasiku.sms.function');
        $smsMessage = Config::get('bukukwasiku.sms.sms');
        if (method_exists(new $smsService, $smsFunction)) {
            $smsMessage = str_replace(":amount", $withdrawAmount, $smsMessage);
            $smsMessage = str_replace(":bkb_reference", $orderItem->bkb_reference, $smsMessage);
            $smsMessage = str_replace(":fee", $fee, $smsMessage);
            $smsService::$smsFunction($user->phone, $smsMessage);
        }
        return Response::json([
            "success" => true,
            "message" => "You will receive confirmation",
        ], 200);
    }
}
