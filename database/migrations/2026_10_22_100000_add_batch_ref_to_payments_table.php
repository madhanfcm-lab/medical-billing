<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payments taken together in one "Receive payment" action share a batch_ref, so one combined receipt can be printed.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('batch_ref', 36)->nullable()->after('emi_plan_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('batch_ref');
        });
    }
};
