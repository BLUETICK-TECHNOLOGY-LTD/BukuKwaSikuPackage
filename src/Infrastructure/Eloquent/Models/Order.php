<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

class Order extends Model
{
    use HasFactory;

    public $fillable = [
        "reference",
        "ordered_id",
        "ordered_type",
        "amount",
        "status",
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.order.table', 'orders'); 
    }

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $orderModel = Config::get('bukukwasiku.order.model');
            $orderReferencePrefex = Config::get('bukukwasiku.order.reference.prefix');
            $orderReferenceLenth = Config::get('bukukwasiku.order.reference.length');
            $latestId = (new $orderModel)::max("id") + 1;
            $model->reference = $orderReferencePrefex . str_pad($latestId, $orderReferenceLenth, '0', STR_PAD_LEFT);
        });

        $vendorScope = Config::get('bukukwasiku.vendor_scope');
        static::addGlobalScope((new $vendorScope));
    }

    public function vendor()
    {
        $vendorModel = Config::get('bukukwasiku.vendor.model');
        $vendorForeignKey = Config::get('bukukwasiku.vendor.foreign_key');
        return $this->belongsTo((new $vendorModel), $vendorForeignKey)->withoutGlobalScopes();
    }

    public function orderItems()
    {
        $vendorOrderModel = Config::get('bukukwasiku.vendor_order.model');
        $vendorOrderForeignKey = Config::get('bukukwasiku.vendor_order.foreign_key');
        $orderItemModel = Config::get('bukukwasiku.order_item.model');
        $orderForeignKey = Config::get('bukukwasiku.order.foreign_key');
        $orderItem = $this->hasManyThrough((new $orderItemModel),(new $vendorOrderModel), $orderForeignKey, $vendorOrderForeignKey)->withoutGlobalScopes();
        if (Request::is("api*")) {
            $orderItem->with("product")->with("vendor");
        }
        return $orderItem->orderBy('id', 'asc');
    }

    public function orderSavingItems()
    {
        $vendorOrderModel = Config::get('bukukwasiku.vendor_order.model');
        $vendorOrderForeignKey = Config::get('bukukwasiku.vendor_order.foreign_key');
        $orderItemModel = Config::get('bukukwasiku.order_item.model');
        $orderForeignKey = Config::get('bukukwasiku.order.foreign_key');
        $orderItems = $this->hasManyThrough((new $orderItemModel),(new $vendorOrderModel), $orderForeignKey, $vendorOrderForeignKey)
            ->withoutGlobalScopes()
            ->whereHas('product.category', function ($query) {
                $query->where('slug', 'savings');
            })
            ->when(Request::is("api*"), function ($query) {
                $query->with(['product', 'vendor']);
            })
            ->orderBy('id', 'asc');
        return $orderItems;
    }

    public function ordered()
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    public function payment()
    {
        $paymentModel = Config::get('bukukwasiku.payment.model');
        return $this->morphOne((new $paymentModel), "payable");
    }
}
