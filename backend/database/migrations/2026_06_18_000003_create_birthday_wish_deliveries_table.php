<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('birthday_wish_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('birthday_year');
            $table->string('status', 20)->default('pending');
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'birthday_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_wish_deliveries');
    }
};
