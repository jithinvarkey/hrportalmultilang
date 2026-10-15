<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_type_department_visibility', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('leave_type_id');
            $table->unsignedBigInteger('department_id');
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['leave_type_id', 'department_id'], 'leave_type_department_visible_unique');
            $table->foreign('leave_type_id')->references('id')->on('leave_types')->onDelete('cascade');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('cascade');
        });

        $businessExcuseId = DB::table('leave_types')->where('code', 'BE')->value('id');
        if ($businessExcuseId) {
            $departments = DB::table('departments')->select('id', 'code')->get();
            $visibleCodes = ['OPS', 'SM'];

            foreach ($departments as $department) {
                DB::table('leave_type_department_visibility')->updateOrInsert(
                    [
                        'leave_type_id' => $businessExcuseId,
                        'department_id' => $department->id,
                    ],
                    [
                        'is_visible' => in_array($department->code, $visibleCodes, true),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_type_department_visibility');
    }
};
