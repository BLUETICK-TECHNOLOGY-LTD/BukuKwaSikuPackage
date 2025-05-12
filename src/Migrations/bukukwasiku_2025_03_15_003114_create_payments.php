<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create(Config::get('bukukwasiku.payment.table', 'payments'), function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("reference")->nullable();
            $table->string("references")->nullable();
            $table->double('amount')->default(0);
            $table->string("payment_method")->nullable();
            $table->string("status")->nullable();
            $table->string("msisdn")->nullable();
            $table->string("receipt")->nullable();
            $table->string("remarks")->nullable();
            $table->double('amount')->default(0);
            $table->string('type')->after('amount');
            $table->nullableUuidMorphs('payable');
            $table->timestamps();
        });

        Schema::create("channels", function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("name");
            $table->timestamps();
        });

        Schema::create("methods", function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("name");
            $table->string("channel_id");
            $table->foreign("channel_id")->references("id")->on("channels");
            $table->timestamps();
        });

        Schema::create(Config::get('bukukwasiku.wallet.table', 'wallets'), function (Blueprint $table) {
            $table->id();
            $table->double("balance")->default(0);
            $table->double("target")->default(0); 
            $table->double("outstanding")->default(0);
            $table->double("withdrawn")->default(0);
            $table->nullableUuidMorphs("walletable");
            $table->timestamps();
        });

        Schema::create(Config::get('bukukwasiku.wallet_withdraw.table', 'wallet_withdraws'), function (Blueprint $table) {
            $table->id();
            $table->nullableUuidMorphs("withdrawable");
            $table->double("amount")->default(0);
            $table->double("fee")->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Config::get('bukukwasiku.wallet_withdraw.table', 'wallet_withdraws'));
        Schema::dropIfExists(Config::get('bukukwasiku.wallet.table', 'wallets'));
        Schema::dropIfExists("methods");
        Schema::dropIfExists("channels");
        Schema::dropIfExists(Config::get('bukukwasiku.payment.table', 'payments'));
    }
};
