<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QMS → HR portal document hand-off.
 *
 * Approved QMS documents arrive as policies (source = 'qms', external_id = QMS document id)
 * that stay unpublished until HR publishes them. Every version received is kept in
 * policy_versions; the policies row always holds the version employees currently see,
 * so a newly received version waits as 'pending' while the old one stays live.
 *
 * Also adds per-employee targeting (audience_type = 'employees' + target_employee_ids).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('id');
            $table->string('external_id', 64)->nullable()->after('source');
            $table->string('document_number', 50)->nullable()->after('title');
            $table->string('owner_department', 150)->nullable()->after('document_number');
            $table->date('expiry_date')->nullable()->after('review_date');
            $table->json('target_employee_ids')->nullable()->after('target_department_ids');
            $table->timestamp('received_at')->nullable();
            $table->unique(['source', 'external_id'], 'policies_source_external_unique');
        });

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->string('version', 20);
            // pending → published → superseded; discarded when replaced/withdrawn before publishing
            $table->string('status', 20)->default('pending');
            $table->string('title', 200);
            $table->longText('content')->nullable();
            $table->string('document_type', 50)->nullable();
            $table->string('document_number', 50)->nullable();
            $table->string('owner_department', 150)->nullable();
            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('change_summary')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('received_from', 150)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['policy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
        Schema::table('policies', function (Blueprint $table) {
            $table->dropUnique('policies_source_external_unique');
            $table->dropColumn(['source', 'external_id', 'document_number', 'owner_department', 'expiry_date', 'target_employee_ids', 'received_at']);
        });
    }
};
