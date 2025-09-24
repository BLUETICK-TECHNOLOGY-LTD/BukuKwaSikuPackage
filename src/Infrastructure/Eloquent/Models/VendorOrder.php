<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class VendorOrder extends Model
{
    use HasFactory;
    public $fillable = ["reference", "vendor_id", "order_id", "amount", "status"];


    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $orderModel = Config::get('bukukwasiku.vendor_order.model');
            $orderReferencePrefex = Config::get('bukukwasiku.vendor_order.reference.prefix');
            $orderReferenceLenth = Config::get('bukukwasiku.vendor_order.reference.length');
            $latestId = (new $orderModel)::max("id") + 1;
            $model->reference = $orderReferencePrefex . str_pad($latestId, $orderReferenceLenth, '0', STR_PAD_LEFT);

            $user = Auth::user();
            $model->vendor_id =  $model->vendor_id ?? ($user->vendor_id ?? null);
        });

        $vendorScope = Config::get('bukukwasiku.vendor_scope');
        static::addGlobalScope((new $vendorScope));
    }

    public function order()
    {
        $vendorModel = Config::get('bukukwasiku.order.model');
        $vendorForeignKey = Config::get('bukukwasiku.order.foreign_key');
        return $this->belongsTo((new $vendorModel), $vendorForeignKey)->withoutGlobalScopes();
    }

    public function orderItems()
    {
        $orderModel = Config::get('bukukwasiku.order_item.model');
        $orderForeignKey = Config::get('bukukwasiku.vendor_order.foreign_key');
        $orderItem = $this->hasMany((new $orderModel),  $orderForeignKey)->withoutGlobalScopes();
        if (Request::is("api*")) {
            $withs = Config::get('bukukwasiku.vendor_order.order_items_with');
            foreach ($withs as $with) {
                if (class_exists($with)) {
                    $orderItem->with($with);
                }
            }
        }
        return $orderItem->orderBy('id', 'asc');
    }

    public function scopeSearch($query)
    {

        $startDate = Carbon::createFromTimeString(request()->start_date??Carbon::now()->startOfYear()->format('Y-m-d H:i:s'))->startOfDay();
        $endDate = Carbon::createFromTimeString(request()->end_date??Carbon::now()->format('Y-m-d H:i:s'))->endOfDay();
        $status = request()->status;

        return $query->when($startDate && $endDate, function ($q) use ($startDate, $endDate) {
            $q->whereBetween('created_at', [$startDate, $endDate]);
        })->when($status, function ($q) use ($status) {
                $q->whereHas('order', function ($query) use ($status) {
                    $query->where('status', $status);
                });
            });
    }
}
