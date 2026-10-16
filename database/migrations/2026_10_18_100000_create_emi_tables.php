<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emi_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_number', 30)->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('treatment_name', 200);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('down_payment', 12, 2)->default(0);
            $table->decimal('financed_amount', 12, 2);              // total - down payment = what the schedule covers
            $table->unsignedSmallInteger('months');                  // number of instalments
            $table->decimal('monthly_amount', 12, 2);
            $table->unsignedTinyInteger('due_day');
            $table->date('start_date');
            $table->date('first_due_date');
            $table->date('end_date');
            $table->decimal('paid_amount', 12, 2)->default(0);       // paid against instalments (excludes down payment)
            $table->decimal('balance_amount', 12, 2);                // still to be paid on instalments
            $table->string('status', 20)->index();
            $table->text('notes')->nullable();
            $table->timestamp('defaulted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('emi_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emi_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status', 20);
            $table->date('paid_on')->nullable();
            $table->timestamps();
            $table->index(['emi_plan_id', 'due_date']);
            $table->index(['due_date', 'status']);
        });

        // Which instalment(s) a payment was applied to (oldest first, excess carried forward).
        Schema::create('emi_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('emi_installment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->nullable();
        });

        // Rescheduling keeps the old schedule as a JSON snapshot.
        Schema::create('emi_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emi_plan_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->decimal('balance_at_time', 12, 2);
            $table->json('old_schedule');
            $table->unsignedSmallInteger('new_months');
            $table->decimal('new_monthly_amount', 12, 2);
            $table->date('new_start_date');
            $table->unsignedTinyInteger('new_due_day');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        // payments.emi_plan_id was created in Phase 3 without a constraint. SQLite cannot add one via ALTER.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('payments', fn (Blueprint $t) => $t->foreign('emi_plan_id')->references('id')->on('emi_plans')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('payments', fn (Blueprint $t) => $t->dropForeign(['emi_plan_id']));
        }
        Schema::dropIfExists('emi_reschedules');
        Schema::dropIfExists('emi_payment_allocations');
        Schema::dropIfExists('emi_installments');
        Schema::dropIfExists('emi_plans');
    }
};
