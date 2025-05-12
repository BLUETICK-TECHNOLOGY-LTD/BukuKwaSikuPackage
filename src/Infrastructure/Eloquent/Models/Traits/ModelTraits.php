<?php

namespace BukukwasikuPackage\Presentation\Models\Traits;

use Illuminate\Support\Facades\Request;

trait ModelTraits
{
    public $per_page_limit = 20;
    public function scopeLimitTake($query)
    {
        $per_page = Request::input('per_page', $this->per_page_limit);
        $per_page = is_numeric($per_page) ? $per_page : $this->per_page_limit;
        $per_page = intval($per_page);

        $offset = Request::input('page', 0);
        $offset = is_numeric($offset) ? $offset : 0;
        $offset = intval($offset);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($per_page < 0) {
            $per_page = 0;
        }
        return $query->offset($offset * $per_page)->limit($per_page);
    }

    public function scopeRelated($query)
    {
        foreach ($this->relates ?? explode(',', Request::input('related', '')) as $related) {
            if (method_exists($this, $related)) {
                $query->with(trim($related));
            }
        }
        return $query;
    }
}
