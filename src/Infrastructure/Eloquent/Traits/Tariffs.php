<?php

namespace BukukwasikuPackage\Infrastructure\Eloquent\Traits;

use Exception;

trait Tariffs
{
    //
    public static function productTariffs()
    {
        return collect([
            [
                "min" => 1000,
                "max" => 4999,
                "charges" => 400
            ],
            [
                "min" => 5000,
                "max" => 9999,
                "charges" => 700
            ],
            [
                "min" => 10000,
                "max" => 49999,
                "charges" => 1500
            ],
            [
                "min" => 50000,
                "max" => 99999,
                "charges" => 2500
            ],
            [
                "min" => 100000,
                "max" => 499999,
                "charges" => 4000
            ],
            [
                "min" => 500000,
                "max" => 999999,
                "charges" => 7000
            ],
            [
                "min" => 1000000,
                "max" => 1999999,
                "charges" => 10000
            ],
            [
                "min" => 2000000,
                "max" => 2999999,
                "charges" => 15000
            ],
            [
                "min" => 3000000,
                "max" => 3999999,
                "charges" => 20000
            ],
            [
                "min" => 4000000,
                "max" => 5000000,
                "charges" => 25000
            ],
        ]);
    }

    public static function savingsWithdrawalTariffs()
    {
        return collect([
            ["min" => 1000, "max" => 4999, "charges" => 400],
            ["min" => 5000, "max" => 9999, "charges" => 700],
            ["min" => 10000, "max" => 49999, "charges" => 1500],
            ["min" => 50000, "max" => 99999, "charges" => 2500],
            ["min" => 100000, "max" => 499999, "charges" => 4000],
            ["min" => 500000, "max" => 999999, "charges" => 7000],
            ["min" => 1000000, "max" => 1999999, "charges" => 10000],
            ["min" => 2000000, "max" => 2999999, "charges" => 15000],
            ["min" => 3000000, "max" => 3999999, "charges" => 20000],
            ["min" => 4000000, "max" => 5000000, "charges" => 25000],
        ]);
    }

    public static function checkSavingCharges($amount)
    {
        foreach (static::savingsWithdrawalTariffs() as $tariff) {
            if ($amount >= $tariff['min'] && $amount <= $tariff['max']) {
                return $tariff['charges'];
            }
        }
        if ($amount < 100) {
            throw new Exception("Minimum withdraw amount is TZS 1,000");
        }
        throw new Exception("Maximum withdraw amount is TZS 5,000,000");
    }


    public static function checkCharges($amount)
    {
        foreach (static::savingsWithdrawalTariffs() as $tariff) {
            if ($amount >= $tariff['min'] && $amount <= $tariff['max']) {
                return $tariff['charges'];
            }
        }
        if ($amount < 100) {
            throw new Exception("Minimum withdraw amount is TZS 1,000");
        }
        throw new Exception("Maximum withdraw amount is TZS 5,000,000");
    }
}
