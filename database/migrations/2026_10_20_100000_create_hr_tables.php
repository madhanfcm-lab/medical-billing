<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 30)->unique();
            $table->string('name', 150)->index();
            $table->string('designation', 100)->nullable();
            $table->string('phone', 10)->nullable();
            $table->date('joining_date');
            $table->boolean('is_active')->default(true)->index();
            $this->audit($table);
            $table->softDeletes();
        });

        // One row per employee per day, enforced by the database.
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date')->index();
            $table->string('status', 12);
            $table->string('remark')->nullable();
            $this->audit($table);
            $table->unique(['employee_id', 'attendance_date']);
        });

        // Manual entries only: no salary calculation anywhere in the app.
        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('payment_date')->index();
            $table->date('for_month')->index();           // always the 1st of the month the salary is for
            $table->decimal('amount', 12, 2);
            $table->string('mode', 20);
            $table->string('reference_no', 100)->nullable();
            $table->string('notes')->nullable();
            $this->audit($table);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employees');
    }

    private function audit(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }
};
