<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The standard price at the moment of billing, so a price that was lowered (or raised) on the bill stays visible later.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('list_price', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('list_price');
        });
    }
};
