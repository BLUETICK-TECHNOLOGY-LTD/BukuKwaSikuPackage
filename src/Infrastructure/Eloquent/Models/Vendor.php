<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Vendor extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.vendor.table', 'vendors');
    }
}