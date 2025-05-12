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
        Schema::create(Config::get('bukukwasiku.order.table', 'orders'), function (Blueprint $table) {
            $table->id();
            $table->string("reference");
            $table->nullableUuidMorphs("ordered");
            $table->double("amount")->default(0);
            $table->string("status")->default('pending');
            $table->nullableUuidMorphs('orderer');
            $table->timestamps();
        });

        Schema::create(Config::get('bukukwasiku.vendor_order.table', 'vendor_orders'), function (Blueprint $table) {
            $table->id();
            $table->string("reference");
            $table->uuid("vendor_id")->nullable();
            $table->foreign("vendor_id")->references("id")->on("vendors")->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId("order_id")->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->double("amount")->default(0);
            $table->string("status")->default('pending');
            $table->timestamps();
        });

        Schema::create(Config::get('bukukwasiku.order_item.table', 'order_items'), function (Blueprint $table) {
            $table->id();
            $table->foreignId("vendor_order_id")->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId("product_id")->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->integer("quantity")->default(0);
            $table->string("price_name")->nullable();
            $table->double("price")->default(0);
            $table->double('paid_amount')->default(0)->after('price');
            $table->uuid("vendor_id")->nullable();
            $table->foreign("vendor_id")->references("id")->on("vendors")->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Config::get('bukukwasiku.order_item.table', 'order_items'));
        Schema::dropIfExists(Config::get('bukukwasiku.vendor_order.table', 'vendor_orders'));
        Schema::dropIfExists(Config::get('bukukwasiku.order.table', 'orders'));
    }
};
