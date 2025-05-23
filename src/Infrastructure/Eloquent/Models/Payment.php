<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class Payment extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.payment.table', 'payments');
    }

    public $fillable = [
        'reference',
        'amount',
        'msisdn',
        "receipt",
        'payable_type',
        "payable_id",
        "payment_method",
        "type",
        "references"
    ];

    public $incrementing = false;

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }
    public function payable()
    {
        return $this->morphTo()->withoutGlobalScopes();
    }
}
