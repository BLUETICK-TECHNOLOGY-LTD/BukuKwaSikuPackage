<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class MemberDetails extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.member_detail.table', 'member_details');
    }

    public $fillable = [
        'name',
        'member_id',
        'description',
        'price',
        'order_item_id',
        'profile',
        'picture_front',
        'picture_back'

    ];

    public $incrementing = false;

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            // $model->id = (string) Str::uuid();
        });
    }
}
