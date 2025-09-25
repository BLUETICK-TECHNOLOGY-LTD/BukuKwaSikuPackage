<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    public function scopeSearch($query)
    {
        $filterable = [
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
            "verified_at",
            "charge_amount",
            "type",
        ];

        foreach ($filterable as $field) {
            if (request()->filled($field)) {
                $value = request()->input($field);
                if (in_array($field, ['bkb_reference', 'price_name', 'frequency'])) {
                    $query->where($field, 'LIKE', "%$value%");
                } else {
                    $query->where($field, $value);
                }
            }
        }
        if (request()->filled('start_date')) {
            $query->whereDate('created_at', '>=', request()->input('start_date'));
        }
        if (request()->filled('end_date')) {
            $query->whereDate('created_at', '<=', request()->input('end_date'));
        }
        if (request()->filled('start_next_payment_date')) {
            $query->whereDate('next_payment_date', '>=', request()->input('start_next_payment_date'));
        }
        if (request()->filled('end_next_payment_date')) {
            $query->whereDate('next_payment_date', '<=', request()->input('end_next_payment_date'));
        }
        if (request()->filled('status')) {
            $status = request()->input('status');
            if ($status == "paid") {
                $query->where("outstanding_amount", "<=", 0);
            }
            if ($status == "paid") {
                $query->where("outstanding_amount", "<=", 0);
            }
            if ($status == "pending") {
                $query->where("outstanding_amount", ">", 0)->where("paid_amount", ">", "0");
            }
            if ($status == "not_paid") {
                $query->where("outstanding_amount", ">", 0)->where("paid_amount", "<=", "0");
            }
        }
        return $query;
    }

    public function status(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->outstanding_amount <= 0) {
                    return "paid";
                }
                if ($this->outstanding_amount > 0 && $this->paid_amount > 0) {
                    return "pending";
                }
                if ($this->outstanding_amount > 0 && $this->paid_amount <= 0) {
                    return "not_paid";
                }
            }
        );
    }

    public $appends = ["status"];
}
