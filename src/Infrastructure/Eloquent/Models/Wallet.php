<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class Wallet extends Model
{
    use HasFactory;
    public $fillable = ["walletable_id", "walletable_type", "balance", 'outstanding', 'target', 'withdrawn'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.wallet.table', 'wallets');
    }

    protected $hidden = [
        'id',
        'walletable_id',
        'walletable_type',
        'created_at',
    ];

    public static function updateBalance($user)
    {
        $orderModel = Config::get('bukukwasiku.order.model');
        $orderSavingFunction = Config::get('bukukwasiku.order.savings');
        if (!method_exists(new $orderModel, $orderSavingFunction)) {
            return;
        }
        $walletModel = Config::get('bukukwasiku.wallet.model');
        $wallet = (new $walletModel)->where("walletable_id", $user->id)->where("walletable_type", get_class($user))->first();
        if (!$wallet) {
            $wallet = (new $walletModel)->create([
                "walletable_id" => $user->id,
                "walletable_type" => get_class($user),
                "balance" => 0,
            ]);
        }
        if ($wallet) {
            $orders = (new $orderModel)->where("ordered_id", $user->id)->where("ordered_type", get_class($user))->get();
            $total = 0;
            $outstanding = 0;
            $target = 0;
            $withdrawn = 0;
            foreach ($orders as $order) {
                $total_paid_amount = $order->$orderSavingFunction->sum('total_paid_amount');
                $withdrawn_amount = $order->$orderSavingFunction->sum('withdrawn_amount');
                $saving_target = $order->$orderSavingFunction->sum('price');
                $outstanding_amount = $order->$orderSavingFunction->sum('outstanding_amount');
                $withdrawn += $withdrawn_amount;
                $target += $saving_target;
                $outstanding += $outstanding_amount;
                $total += $total_paid_amount - $withdrawn_amount;
            }
            $wallet->balance = $total;
            $wallet->withdrawn = $withdrawn;
            $wallet->target = $target;
            $wallet->outstanding = $outstanding;
            $wallet->save();
        }
    }

    public function walletable()
    {
        return $this->morphTo();
    }
}
