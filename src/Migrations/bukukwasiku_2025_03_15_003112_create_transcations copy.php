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
        Schema::create(Config::get('bukukwasiku.transaction.table', 'transactions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string("nafuu_wallet_id");
            $table->double("amount")->default(0);
            $table->double("balance")->default(0);
            $table->enum("action", ['deposit', 'withdraw', 'use'])->nullable();
            $table->string("transid");
            $table->string("description");
            $table->nullableUuidMorphs("transactionable");
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Config::get('bukukwasiku.transaction.table', 'transactions'));
    }
};
