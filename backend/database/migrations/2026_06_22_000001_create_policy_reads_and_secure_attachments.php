<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['policy_id', 'employee_id']);
        });

        DB::table('policies')->whereNotNull('attachment_path')->orderBy('id')->each(function ($policy) {
            if (Storage::disk('public')->exists($policy->attachment_path)
                && !Storage::disk('local')->exists($policy->attachment_path)) {
                Storage::disk('local')->put($policy->attachment_path, Storage::disk('public')->get($policy->attachment_path));
                Storage::disk('public')->delete($policy->attachment_path);
            }
        });
    }

    public function down(): void
    {
        DB::table('policies')->whereNotNull('attachment_path')->orderBy('id')->each(function ($policy) {
            if (Storage::disk('local')->exists($policy->attachment_path)
                && !Storage::disk('public')->exists($policy->attachment_path)) {
                Storage::disk('public')->put($policy->attachment_path, Storage::disk('local')->get($policy->attachment_path));
                Storage::disk('local')->delete($policy->attachment_path);
            }
        });
        Schema::dropIfExists('policy_reads');
    }
};
