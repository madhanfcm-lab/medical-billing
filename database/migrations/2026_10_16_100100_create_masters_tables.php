<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $this->audit($table);
            $table->softDeletes();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('specialization', 150)->nullable();
            $table->string('phone', 10)->nullable();
            $table->decimal('consultation_fee', 12, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $this->audit($table);
            $table->softDeletes();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('category', 30)->index();
            $table->decimal('default_price', 12, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $this->audit($table);
            $table->softDeletes();
            $table->unique(['name', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('doctors');
        Schema::dropIfExists('departments');
    }

    private function audit(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }
};
