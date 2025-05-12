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
        Schema::create(Config::get('bukukwasiku.member_detail.table', 'member_details'), function (Blueprint $table) {
            $table->id();
            $table->string("name")->nullable();
            $table->string("member_id")->nullable();
            $table->string("profile")->nullable();
            $table->string("picture_front")->nullable();
            $table->string("picture_back")->nullable();
            $table->double("price")->default(0);
            $table->mediumText("description")->nullable();
            $table->timestamp("start_at")->nullable();
            $table->timestamp("end_at")->nullable();
            $table->foreignId("order_item_id")->nullable()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Config::get('bukukwasiku.member_detail.table', 'member_details'));
    }
};
