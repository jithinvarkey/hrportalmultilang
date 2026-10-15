<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds optional document-attachment columns to contract renewal requests so a
 * supporting file (signed renewal, addendum, approval letter, etc.) can be
 * uploaded against a renewal request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_renewal_requests', function (Blueprint $table) {
            $table->string('document_path', 500)->nullable()->after('notes');
            $table->string('document_name', 255)->nullable()->after('document_path');
            $table->string('document_mime', 100)->nullable()->after('document_name');
            $table->unsignedBigInteger('document_size')->nullable()->after('document_mime');
        });
    }

    public function down(): void
    {
        Schema::table('contract_renewal_requests', function (Blueprint $table) {
            $table->dropColumn(['document_path', 'document_name', 'document_mime', 'document_size']);
        });
    }
};
