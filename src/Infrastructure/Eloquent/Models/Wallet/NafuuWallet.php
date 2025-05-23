<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Models\Wallet;

use BukukwasikuPackage\Infrastructure\Eloquent\Models\Traits\Tariffs;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class NafuuWallet extends Model
{
    use HasFactory, SoftDeletes, Tariffs;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = Config::get('bukukwasiku.nafuu_wallet.table', 'nafuu_wallets');
    }

    public $fillable = ['name', 'ownerable_id', 'ownerable_type', 'balance', 'reference','withdrawn_amount','used_amount','charge_amount'];

    public $incrementing = false;

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id = (string) Str::uuid();
            $model->reference = static::generateLuhnCardNumber();
        });
    }

    public function ownerable()
    {
        return $this->morphTo();
    }

    public function transactions()
    {
        $transactionConfigModel = Config::get('bukukwasiku.transaction.model');
        return $this->hasMany($transactionConfigModel, 'nafuu_wallet_id');
    }

    public function last10transactions()
    {
        return $this->transactions()->latest()->take(10);
    }

    public function tariffs(): Attribute
    {
        return Attribute::make(
            get: function () {
                return static::productTariffs();
            }
        );
    }

    protected $appends = ["tariffs"];

    public static function generateLuhnCardNumber(): string
    {
        $nafuuConfigModel = Config::get('bukukwasiku.nafuu_wallet.model');
        do {
            $prefix = "1";
            $number = $prefix . str_pad(random_int(0, 999999999999999), 15, "0", STR_PAD_LEFT);
            $number . static::calculateLuhnChecksum($number);
        } while ($nafuuConfigModel::where("reference", $number)->exists());
        return $number;
    }

    public static function calculateLuhnChecksum(string $number): int
    {
        $sum = 0;
        $alt = false;
        $digits = array_reverse(str_split($number));
        foreach ($digits as $digit) {
            $digit = (int) $digit;
            if ($alt) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
            $alt = !$alt;
        }
        return (10 - ($sum % 10)) % 10;
    }
}
