<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Models\Wallet;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.transaction.table', 'transactions');
    }

    public const DEPOSIT = 'deposit';
    public const USE = 'use';
    public const CHARGES = 'charge';
    public const WITHDRAW = 'withdraw';

    public $fillable = [
        'nafuu_wallet_id',
        'amount',
        'balance',
        'action',
        'transid',
        'description',
        'transactionable_id',
        'transactionable_type'
    ];

    public $incrementing = false;

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
        });
    }

    public function nafuu_wallet()
    {
        return $this->belongsTo(NafuuWallet::class);
    }

    public function transactionable()
    {
        return $this->morphTo();
    }

    public static function balance($user)
    {
        $nafuuWalletModel = Config::get('bukukwasiku.nafuu_wallet.model');
        $wallet = $nafuuWalletModel::firstOrCreate(
            ["ownerable_id" => $user->id, "ownerable_type" => get_class($user)],
            ["balance" => 0, "name" => $user->name ?? $user->phone]
        );
        return $nafuuWalletModel::whereId($wallet->id)->with('last10transactions')->first();
    }

    public static function checkSufficient($user, $amount)
    {
        $nafuuWalletModel = Config::get('bukukwasiku.nafuu_wallet.model');
        $wallet = $nafuuWalletModel::firstOrCreate(
            ["ownerable_id" => $user->id, "ownerable_type" => get_class($user)],
            ["balance" => 0, "name" => $user->name ?? $user->phone]
        );
        if ($wallet->balance < $amount) {
            throw new Exception("Insufficient balance.");
        }
    }

    public static function transact($user, $amount, $action, $description, $transactionable, $dbTransaction = true)
    {

        if (!in_array($action, [static::DEPOSIT, static::WITHDRAW, static::USE, static::CHARGES])) {
            throw new Exception("Invalid action.");
        }
        try {
            if ($dbTransaction) {
                DB::beginTransaction();
            }

            $nafuuWalletModel = Config::get('bukukwasiku.nafuu_wallet.model');
            $transactionConfigModel = Config::get('bukukwasiku.transaction.model');
            $wallet = $nafuuWalletModel::firstOrCreate(
                ["ownerable_id" => $user->id, "ownerable_type" => get_class($user)],
                ["balance" => 0, "name" => $user->name ?? $user->phone]
            );

            if (in_array($action, [static::WITHDRAW, static::USE, static::CHARGES]) && $wallet->balance < $amount) {
                throw new Exception("Insufficient balance.");
            }

            $lastBalance = $transactionConfigModel::where('nafuu_wallet_id', $wallet->id)->latest()->value('balance') ?? 0;

            $transaction = $transactionConfigModel::create([
                'nafuu_wallet_id' => $wallet->id,
                'amount' => $amount,
                'balance' => $action === 'deposit' ? $lastBalance + $amount : $lastBalance - $amount,
                'action' => $action,
                'description' => $description,
                'transid' => 'NFW' . Carbon::now()->timestamp . strtoupper(Str::random(4)),
                "transactionable_id" => $transactionable->id,
                "transactionable_type" => get_class($transactionable),
            ]);

            $trans = $transactionConfigModel::where('nafuu_wallet_id', $wallet->id);
            $wallet->balance = $transaction->balance;
            $wallet->withdrawn_amount = $trans->clone()->where("action", static::WITHDRAW)->sum("amount");
            $wallet->used_amount = $trans->clone()->where("action", static::USE)->sum("amount");
            $wallet->charge_amount = $trans->clone()->where("action", static::CHARGES)->sum("amount");
            $wallet->save();

            if ($dbTransaction) {
                DB::commit();
            }

            return $transaction;
        } catch (Exception $e) {
            if ($dbTransaction) {
                DB::rollBack();
            }
            throw $e;
        }
    }
}
