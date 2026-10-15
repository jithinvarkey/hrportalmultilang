<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Management Module Schema
 *
 * asset_categories  : grouping (IT Equipment, Furniture, Vehicle …)
 * assets            : master catalogue of every physical/digital asset
 * asset_assignments : who holds which asset from when to when
 * asset_maintenance : maintenance/repair log per asset
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100)->comment('Category display name');
            $table->string('slug', 120)->unique();
            $table->string('icon', 50)->nullable()->comment('Material icon name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('category_id')->nullable()
                  ->constrained('asset_categories')->nullOnDelete();
            $table->string('name', 200)->comment('Asset display name');
            $table->string('asset_code', 100)->unique()->comment('Internal asset tag / barcode');
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('serial_number', 150)->nullable()->unique();
            $table->text('description')->nullable();

            // Status: available | assigned | under_maintenance | disposed | lost
            $table->string('status', 30)->default('available');

            // Condition: new | good | fair | poor
            $table->string('condition', 20)->default('good');

            // Financials
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('vendor', 150)->nullable();
            $table->string('warranty_expiry', 20)->nullable()->comment('YYYY-MM-DD');

            // Location & custody
            $table->string('location', 150)->nullable()->comment('Physical location / office');
            $table->foreignId('custodian_employee_id')->nullable()
                  ->constrained('employees')->nullOnDelete()
                  ->comment('Current holder');

            // Optional attachment (purchase order / invoice)
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category_id']);
            $table->index('custodian_employee_id');
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('assigned_date');
            $table->date('return_date')->nullable()->comment('Null = currently assigned');
            $table->string('condition_at_assign', 20)->default('good');
            $table->string('condition_at_return', 20)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'return_date']);
            $table->index(['employee_id', 'return_date']);
        });

        Schema::create('asset_maintenance', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('type', 50)->comment('repair | service | inspection | upgrade');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->string('vendor', 150)->nullable();
            $table->string('status', 30)->default('scheduled')
                  ->comment('scheduled | in_progress | completed | cancelled');
            $table->text('resolution')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
    }
};
