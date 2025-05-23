<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class ProductCategory extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.product_category.table', 'product_categories');
    }
}
