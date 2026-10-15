<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('source_system', 50)->nullable()->after('created_by');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_system');
            $table->unique(['source_system', 'source_id'], 'assets_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique('assets_source_unique');
            $table->dropColumn(['source_system', 'source_id']);
        });
    }
};
