<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. No foreign key on user_id so history survives user removal.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();   // null = system / scheduler
            $table->string('event', 30)->index();                          // created, updated, cancelled, deleted, ...
            $table->string('subject_type', 50);                            // morph alias: invoice, payment, ...
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 150);                          // e.g. INV-2026-00001, kept even if the record changes
            $table->string('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
