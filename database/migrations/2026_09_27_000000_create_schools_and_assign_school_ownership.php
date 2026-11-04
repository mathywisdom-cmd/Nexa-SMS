<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateSchoolsAndAssignSchoolOwnership extends Migration
{
    /** School-owned tables. Reference/catalog tables such as states and blood_groups remain shared. */
    protected $tables = [
        'users', 'class_types', 'my_classes', 'sections', 'subjects', 'student_records',
        'exams', 'marks', 'exam_records', 'grades', 'dorms', 'pins', 'books',
        'book_requests', 'staff_records', 'payments', 'payment_records', 'receipts',
        'time_table_records', 'time_slots', 'time_tables', 'promotions', 'settings', 'skills',
    ];

    public function up()
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('registration_number')->nullable();
            $table->string('logo')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('status')->default('active');
            $table->string('timezone', 64)->default('UTC');
            $table->string('currency', 3)->default('USD');
            $table->timestamps();
        });

        DB::table('schools')->insert([
            'name' => 'Default School', 'code' => 'DEFAULT', 'status' => 'active',
            'timezone' => 'UTC', 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $schoolId = DB::table('schools')->where('code', 'DEFAULT')->value('id');

        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('school_id')->nullable()->index();
            });
            DB::table($tableName)->whereNull('school_id')->update(['school_id' => $schoolId]);
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('school_id')->references('id')->on('schools')->onDelete('restrict');
            });
        }

        // Platform owners remain platform-level; existing school accounts belong to the migrated school.
        if (Schema::hasTable('users')) {
            DB::table('users')->where('user_type', 'super_admin')->update(['school_id' => null]);
        }

        // Values that used to be globally unique are only required to be unique within a school.
        $this->replaceUnique('users', 'users_code_unique', ['school_id', 'code']);
        $this->replaceUnique('my_classes', 'my_classes_class_type_id_name_unique', ['school_id', 'class_type_id', 'name']);
        $this->replaceUnique('sections', 'sections_name_my_class_id_unique', ['school_id', 'my_class_id', 'name']);
        $this->replaceUnique('subjects', 'subjects_my_class_id_name_unique', ['school_id', 'my_class_id', 'name']);
        $this->replaceUnique('student_records', 'student_records_adm_no_unique', ['school_id', 'adm_no']);
        $this->replaceUnique('exams', 'exams_term_year_unique', ['school_id', 'term', 'year']);
        $this->replaceUnique('dorms', 'dorms_name_unique', ['school_id', 'name']);
        $this->replaceUnique('payments', 'payments_ref_no_unique', ['school_id', 'ref_no']);
        $this->replaceUnique('payment_records', 'payment_records_ref_no_unique', ['school_id', 'ref_no']);
        $this->replaceUnique('staff_records', 'staff_records_code_unique', ['school_id', 'code']);
        $this->replaceUnique('time_table_records', 'time_table_records_name_unique', ['school_id', 'name']);
        $this->replaceUnique('time_table_records', 'time_table_records_my_class_id_exam_id_year_unique', ['school_id', 'my_class_id', 'exam_id', 'year']);
    }

    public function down()
    {
        $this->restoreUnique('users', ['school_id', 'code'], 'code');
        $this->restoreUnique('my_classes', ['school_id', 'class_type_id', 'name'], ['class_type_id', 'name']);
        $this->restoreUnique('sections', ['school_id', 'my_class_id', 'name'], ['name', 'my_class_id']);
        $this->restoreUnique('subjects', ['school_id', 'my_class_id', 'name'], ['my_class_id', 'name']);
        $this->restoreUnique('student_records', ['school_id', 'adm_no'], 'adm_no');
        $this->restoreUnique('exams', ['school_id', 'term', 'year'], ['term', 'year']);
        $this->restoreUnique('dorms', ['school_id', 'name'], 'name');
        $this->restoreUnique('payments', ['school_id', 'ref_no'], 'ref_no');
        $this->restoreUnique('payment_records', ['school_id', 'ref_no'], 'ref_no');
        $this->restoreUnique('staff_records', ['school_id', 'code'], 'code');
        $this->restoreUnique('time_table_records', ['school_id', 'name'], 'name');
        $this->restoreUnique('time_table_records', ['school_id', 'my_class_id', 'exam_id', 'year'], ['my_class_id', 'exam_id', 'year']);

        foreach (array_reverse($this->tables) as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'school_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['school_id']);
                $table->dropIndex(['school_id']);
                $table->dropColumn('school_id');
            });
        }

        Schema::dropIfExists('schools');
    }

    protected function replaceUnique($tableName, $oldIndex, array $columns)
    {
        Schema::table($tableName, function (Blueprint $table) use ($oldIndex, $columns) {
            $table->dropUnique($oldIndex);
            $table->unique($columns);
        });
    }

    protected function restoreUnique($tableName, array $scopedColumns, $originalColumns)
    {
        Schema::table($tableName, function (Blueprint $table) use ($scopedColumns, $originalColumns) {
            $table->dropUnique($scopedColumns);
            $table->unique((array) $originalColumns);
        });
    }
}
