<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('announcement_categories')) {
            Schema::create('announcement_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 120)->unique();
                $table->string('color', 20)->nullable();
                $table->string('icon', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('announcements')) {
            Schema::create('announcements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->nullable()->constrained('announcement_categories')->nullOnDelete();
                $table->string('title', 200);
                $table->text('body')->nullable();
                $table->string('priority', 20)->default('normal');
                $table->boolean('is_pinned')->default(false);
                $table->boolean('is_published')->default(true);
                $table->timestamp('published_at')->nullable();
                $table->date('expires_at')->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
                $table->string('attachment_mime')->nullable();
                $table->unsignedBigInteger('attachment_size')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['is_published', 'published_at']);
            });
        }

        if (!Schema::hasTable('policy_categories')) {
            Schema::create('policy_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 120)->unique();
                $table->string('icon', 50)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('policies')) {
            Schema::create('policies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->nullable()->constrained('policy_categories')->nullOnDelete();
                $table->string('title', 200);
                $table->longText('content')->nullable();
                $table->string('version', 20)->default('1.0');
                $table->date('effective_date')->nullable();
                $table->boolean('requires_acknowledgement')->default(true);
                $table->boolean('is_published')->default(true);
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
                $table->string('attachment_mime')->nullable();
                $table->unsignedBigInteger('attachment_size')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['is_published', 'effective_date']);
            });
        }

        if (!Schema::hasTable('policy_acknowledgements')) {
            Schema::create('policy_acknowledgements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('policy_version', 20)->default('1.0');
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamps();
                $table->unique(['policy_id', 'employee_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('policy_categories');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('announcement_categories');
    }
};
