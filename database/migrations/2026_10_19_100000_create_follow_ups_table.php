<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();          // set from this visit
            $table->foreignId('emi_plan_id')->nullable()->constrained()->nullOnDelete();       // set from this EMI plan
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('follow_up_date');
            $table->time('follow_up_time')->nullable();
            $table->string('reason', 200);
            $table->text('notes')->nullable();
            $table->string('status', 20);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('follow_ups')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'follow_up_date']);
            $table->index(['doctor_id', 'follow_up_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
