<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

class VendorScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        //
        if (Auth::check()) {
            if (Schema::hasColumn($model->getTable(), "vendor_id")) {
                $user = Auth::user();
                if (!request()->has("no-vendor-id")) {
                    if (request()->has('vendor')) {
                        $builder->where($model->getTable() . '.vendor_id', Session::get("vendor_id", Request::input('vendor')));
                    } else {
                        $builder->where($model->getTable() . '.vendor_id', Session::get("vendor_id", $user->vendor_id));
                    }
                }
            }
        } else {
            if (Schema::hasColumn($model->getTable(), "vendor_id")) {
                $vendor = Request::input('vendor');
                if (!request()->has("no-vendor-id")) {
                    $builder->where($model->getTable() . '.vendor_id', Session::get("vendor_id", $vendor));
                }
            }
        }
    }
}
