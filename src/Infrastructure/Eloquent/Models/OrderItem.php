<?php

namespace BukukwasikuPackage\Presentation\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;

class OrderItem extends Model
{
    protected $fillable = [
        "vendor_order_id",
        "bkb_reference",
        "product_id",
        "quantity",
        "price_name",
        "vendor_id",
        "price",
        "paid_amount",
        "n_card",

        "no_of_installment",
        "no_of_paid_installment",
        "no_of_unpaid_installment",
        "amount_per_day",
        "total_paid_amount",
        "next_payment_date",
        "start_payment_date",
        "outstanding_amount",
        "due_days",
        "frequency",
        "initial_deposit",
        "push_amount",
        "due_amount",
        "amount_to_pay",
        "opening_amount",

        "withdrawn_at",
        "withdrawn_amount",

        "charge_amount"
    ];

    protected $hidden = ["updated_at", "id"];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.order_item.table', 'order_items'); 
    }

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $user = Auth::user();
            $model->vendor_id =  $model->vendor_id ?? ($user->vendor_id ?? null);

            $multiplier = 1;
            $multiplier = (strtolower($model->frequency) == "weekly") ? 7 : $multiplier;
            $multiplier = (strtolower($model->frequency) == "monthly") ? 30 : $multiplier;
            $model->push_amount = min($model->price, $model->amount_per_day * $multiplier);

            $model->start_payment_date = Carbon::now()->format('Y-m-d H:i:s');
            $model->next_payment_date = Carbon::now()->addDay()->format('Y-m-d H:i:s');
        });

        $vendorScope = Config::get('bukukwasiku.vendor_scope');
        static::addGlobalScope((new $vendorScope));
    }


    public function orderItem()
    {
        $orderModel = Config::get('bukukwasiku.order_item.model');
        $orderForeignKey = Config::get('bukukwasiku.order_item.foreign_key');
        return $this->belongsTo((new $orderModel), $orderForeignKey)->withoutGlobalScopes();
    }

    public function product()
    {
        $productModel = Config::get('bukukwasiku.product.model');
        $productKey = Config::get('bukukwasiku.product.foreign_key');
        $withs = Config::get('bukukwasiku.product.order_with');
        $existsWiths = [];
        if (is_array($withs)) {
            foreach ($withs as $with) {
                if (class_exists($with)) {
                    $existsWiths[] = $with;
                }
            }
        }

        $product =  $this->belongsTo((new $productModel), $productKey);
        if (count($existsWiths) > 0) {
            $product->with($existsWiths);
        }
        return $product->withoutGlobalScopes();
    }

    public function vendor()
    {
        $vendorModel = Config::get('bukukwasiku.vendor.model');
        $vendorKey = Config::get('bukukwasiku.vendor.foreign_key');
        return $this->belongsTo((new $vendorModel), $vendorKey)->withoutGlobalScopes();
    }

    public function vendorOrder()
    {
        $vendorOrderModel = Config::get('bukukwasiku.vendor_order.model');
        $vendorOrderForeignKey = Config::get('bukukwasiku.vendor_order.foreign_key');
        return $this->belongsTo((new $vendorOrderModel), $vendorOrderForeignKey)->withoutGlobalScopes();
    }
}
