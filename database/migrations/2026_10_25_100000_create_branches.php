<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables whose rows belong to one branch. Patients, services, doctors and departments stay shared by the whole hospital. */
    private const BRANCH_TABLES = ['visits', 'invoices', 'payments', 'emi_plans', 'follow_ups', 'employees', 'attendances', 'salary_payments', 'activity_logs'];

    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 12)->unique();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });

        // Everything that exists today becomes the first branch, so nothing changes for a single-clinic install.
        $setting = fn (string $key) => DB::table('settings')->where('key', $key)->value('value');
        $main = DB::table('branches')->insertGetId([
            'name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true,
            'address' => $setting('hospital_address'), 'phone' => $setting('hospital_phone'), 'email' => $setting('hospital_email'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::BRANCH_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            });
            DB::table($name)->update(['branch_id' => $main]);
        }

        DB::table('users')->orderBy('id')->pluck('id')->each(
            fn ($id) => DB::table('branch_user')->insert(['branch_id' => $main, 'user_id' => $id])
        );
    }

    public function down(): void
    {
        foreach (self::BRANCH_TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('branch_id'));
        }
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
    }
};
