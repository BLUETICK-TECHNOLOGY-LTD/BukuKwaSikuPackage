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
        Schema::create(Config::get('bukukwasiku.nafuu_wallet.table', 'nafuu_wallets'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string("reference")->nullable();
            $table->string("name")->nullable();
            $table->nullableUuidMorphs("ownerable");
            $table->double("balance")->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Config::get('bukukwasiku.nafuu_wallet.table', 'nafuu_wallets'));
    }
};
