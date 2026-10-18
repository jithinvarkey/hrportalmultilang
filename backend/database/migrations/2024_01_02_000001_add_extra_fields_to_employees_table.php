<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddExtraFieldsToEmployeesTable extends Migration
{

    /**
     * MySQL re-defines the ENUM in place. SQLite (used by the test suite) has no MODIFY COLUMN and stores
     * enums as CHECK constraints, so there the column is recreated as a plain string with the same default.
     */
    private function setStatusEnum(string $table, string $mysqlDefinition, string $default): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN status {$mysqlDefinition}");
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table($table, fn (Blueprint $t) => $t->string('status', 30)->default($default));
    }
    public function up()
    {
        // Fix status enum to include probation
        $this->setStatusEnum('employees', "ENUM('active','inactive','terminated','on_leave','probation') DEFAULT 'active'", 'active');

        Schema::table('employees', function (Blueprint $table) {
            // Personal
            $table->string('prefix', 10)->nullable()->after('employee_code');
            $table->string('arabic_name', 200)->nullable()->after('last_name');
            $table->string('work_phone', 20)->nullable()->after('phone');
            $table->string('extension', 10)->nullable()->after('work_phone');
            $table->string('nationality', 100)->nullable()->after('country');

            // Employment
            $table->string('mode_of_employment', 50)->nullable()->after('employment_type');
            $table->string('role', 50)->nullable()->after('mode_of_employment');
            $table->date('confirmation_date')->nullable()->after('hire_date');
            $table->unsignedTinyInteger('probation_period')->default(0)->after('confirmation_date');
            $table->unsignedSmallInteger('years_of_experience')->nullable()->after('probation_period');

            // Emergency
            $table->string('emergency_contact_relation', 50)->nullable()->after('emergency_contact_phone');
        });
    }

    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'prefix','arabic_name','work_phone','extension','nationality',
                'mode_of_employment','role','confirmation_date','probation_period',
                'years_of_experience','emergency_contact_relation',
            ]);
        });
        $this->setStatusEnum('employees', "ENUM('active','inactive','terminated','on_leave') DEFAULT 'active'", 'active');
    }
}
