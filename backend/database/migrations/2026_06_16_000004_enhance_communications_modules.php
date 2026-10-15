<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('announcement_reads')) {
            Schema::create('announcement_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->timestamp('read_at')->useCurrent();
                $table->timestamps();
                $table->unique(['announcement_id', 'employee_id']);
            });
        }

        if (!Schema::hasTable('announcement_reactions')) {
            Schema::create('announcement_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('emoji', 16)->default('like');
                $table->timestamps();
                $table->unique(['announcement_id', 'employee_id']);
            });
        }

        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table) {
                if (!Schema::hasColumn('announcements', 'audience_type')) {
                    $table->string('audience_type', 20)->default('all')->after('priority');
                }
                if (!Schema::hasColumn('announcements', 'target_department_ids')) {
                    $table->json('target_department_ids')->nullable()->after('audience_type');
                }
                if (!Schema::hasColumn('announcements', 'target_roles')) {
                    $table->json('target_roles')->nullable()->after('target_department_ids');
                }
                if (!Schema::hasColumn('announcements', 'scheduled_at')) {
                    $table->timestamp('scheduled_at')->nullable()->after('published_at');
                }
            });
        }

        if (Schema::hasTable('policies')) {
            Schema::table('policies', function (Blueprint $table) {
                if (!Schema::hasColumn('policies', 'status')) {
                    $table->string('status', 20)->default('approved')->after('is_published');
                }
                if (!Schema::hasColumn('policies', 'review_date')) {
                    $table->date('review_date')->nullable()->after('effective_date');
                }
                if (!Schema::hasColumn('policies', 'approved_by')) {
                    $table->foreignId('approved_by')->nullable()->after('created_by')
                        ->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('policies', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('approved_by');
                }
                if (!Schema::hasColumn('policies', 'mandatory')) {
                    $table->boolean('mandatory')->default(false)->after('requires_acknowledgement');
                }
            });
        }

        if (Schema::hasTable('policy_acknowledgements')) {
            Schema::table('policy_acknowledgements', function (Blueprint $table) {
                if (!Schema::hasColumn('policy_acknowledgements', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable()->after('policy_version');
                }
                if (!Schema::hasColumn('policy_acknowledgements', 'user_agent')) {
                    $table->string('user_agent', 255)->nullable()->after('ip_address');
                }
            });

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                try {
                    Schema::table('policy_acknowledgements', function (Blueprint $table) {
                        $table->dropUnique('policy_acknowledgements_policy_id_employee_id_unique');
                    });
                } catch (Throwable $e) {
                    // The old unique key may already be removed on a partially migrated database.
                }

                try {
                    Schema::table('policy_acknowledgements', function (Blueprint $table) {
                        $table->unique(
                            ['policy_id', 'employee_id', 'policy_version'],
                            'policy_ack_unique_per_version'
                        );
                    });
                } catch (Throwable $e) {
                    // The version-aware unique key already exists.
                }
            }
        }

        if (!Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('type', 50);
                $table->string('title', 200);
                $table->string('body', 500)->nullable();
                $table->string('link')->nullable();
                $table->unsignedBigInteger('ref_id')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['employee_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('announcement_reactions');
        Schema::dropIfExists('announcement_reads');

        if (Schema::hasTable('policy_acknowledgements')) {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                try {
                    Schema::table('policy_acknowledgements', function (Blueprint $table) {
                        $table->dropUnique('policy_ack_unique_per_version');
                    });
                } catch (Throwable $e) {
                    // Index already absent.
                }
            }

            Schema::table('policy_acknowledgements', function (Blueprint $table) {
                foreach (['ip_address', 'user_agent'] as $column) {
                    if (Schema::hasColumn('policy_acknowledgements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('policies')) {
            Schema::table('policies', function (Blueprint $table) {
                if (Schema::hasColumn('policies', 'approved_by')) {
                    $table->dropConstrainedForeignId('approved_by');
                }
                foreach (['status', 'review_date', 'approved_at', 'mandatory'] as $column) {
                    if (Schema::hasColumn('policies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table) {
                foreach (['audience_type', 'target_department_ids', 'target_roles', 'scheduled_at'] as $column) {
                    if (Schema::hasColumn('announcements', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
