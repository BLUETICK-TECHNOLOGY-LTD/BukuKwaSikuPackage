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
            $table->double('paid_amount')->default(0);
            $table->uuid("vendor_id")->nullable();
            $table->foreign("vendor_id")->references("id")->on("vendors")->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
        });

        if (Schema::hasTable(Config::get('bukukwasiku.order_item.table', 'order_items'))) {
            Schema::table(Config::get('bukukwasiku.order_item.table', 'order_items'), function (Blueprint $table) {

                // Add new columns only if they don't exist
                if (!Schema::hasColumn(Config::get('bukukwasiku.order_item.table', 'order_items'), 'bkb_reference')) {
                    $table->string("bkb_reference")->nullable()->after("id");
                }
                if (!Schema::hasColumn(Config::get('bukukwasiku.order_item.table', 'order_items'), 'no_of_installment')) {
                    $table->integer("no_of_installment")->default(1);
                } else {
                    $table->integer("no_of_installment")->default(1)->change();
                }
                if (!Schema::hasColumn(Config::get('bukukwasiku.order_item.table', 'order_items'), 'no_of_paid_installment')) {
                    $table->integer("no_of_paid_installment")->default(0);
                } else {
                    $table->integer("no_of_paid_installment")->default(0)->change();
                }
                if (!Schema::hasColumn(Config::get('bukukwasiku.order_item.table', 'order_items'), 'no_of_unpaid_installment')) {
                    $table->integer("no_of_unpaid_installment")->default(1);
                } else {
                    $table->integer("no_of_unpaid_installment")->default(1)->change();
                }

                $table->double("amount_per_day")->default(0)->after('no_of_unpaid_installment');
                $table->double("total_paid_amount")->default(0);
                $table->timestamp("next_payment_date")->nullable();
                $table->timestamp("start_payment_date")->nullable();
                $table->double("outstanding_amount")->default(0);
                $table->double("due_days")->default(0);
                $table->string("n_card")->nullable();
                $table->string('frequency')->default('daily')->after('quantity');
                $table->double('initial_deposit')->default(0);
                $table->double("amount_to_pay")->default(0);
                $table->double("due_amount")->default(0);
                $table->double("push_amount")->default(0);
                $table->double("withdrawn_amount")->default(0);
                $table->timestamp("withdrawn_at")->nullable();
                $table->double("opening_amount")->after('paid_amount')->default(0);
                $table->double("charge_amount")->default(0);
                $table->timestamp("verified_at")->nullable();
            });
        }
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
