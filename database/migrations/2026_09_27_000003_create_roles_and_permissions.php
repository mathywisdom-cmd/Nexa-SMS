<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateRolesAndPermissions extends Migration
{
    protected $permissions = [
        'school.manage' => 'Manage school administration',
        'students.view' => 'View student records', 'students.manage' => 'Create and update students',
        'teachers.view' => 'View teacher records', 'teachers.manage' => 'Manage teachers and staff',
        'parents.view' => 'View parent records', 'parents.manage' => 'Manage parents',
        'classes.view' => 'View classes and sections', 'classes.manage' => 'Manage classes and sections',
        'subjects.view' => 'View subjects', 'subjects.manage' => 'Manage subjects',
        'academic_years.manage' => 'Manage academic years', 'terms.manage' => 'Manage terms',
        'attendance.view' => 'View attendance and reports', 'attendance.mark' => 'Mark and edit attendance',
        'exams.view' => 'View exams', 'exams.manage' => 'Manage exams',
        'marks.view' => 'View results', 'marks.manage' => 'Enter and edit marks',
        'fees.view' => 'View fees and balances', 'fees.manage' => 'Manage fee schedules',
        'payments.record' => 'Record and manage payments', 'library.manage' => 'Manage library records',
        'settings.manage' => 'Manage school settings', 'reports.view' => 'View and export school reports',
        'audit_logs.view' => 'View school audit history',
        'platform.schools.manage' => 'Manage all schools', 'platform.users.manage' => 'Manage platform users',
        'platform.reports.view' => 'View platform reports', 'platform.audit_logs.view' => 'View platform audit logs',
        'platform.settings.manage' => 'Manage platform settings', 'platform.subscriptions.manage' => 'Manage plans and subscriptions',
    ];

    protected $roles = [
        'super_admin' => ['scope' => 'platform', 'label' => 'Platform Owner', 'permissions' => [
            'platform.schools.manage', 'platform.users.manage', 'platform.reports.view',
            'platform.audit_logs.view', 'platform.settings.manage', 'platform.subscriptions.manage',
        ]],
        'admin' => ['scope' => 'school', 'label' => 'School Administrator', 'permissions' => 'all_school'],
        'principal' => ['scope' => 'school', 'label' => 'Principal', 'permissions' => [
            'students.view', 'students.manage', 'teachers.view', 'classes.view', 'classes.manage',
            'subjects.view', 'subjects.manage', 'academic_years.manage', 'terms.manage',
            'attendance.view', 'attendance.mark', 'exams.view', 'exams.manage', 'marks.view', 'marks.manage',
            'reports.view', 'audit_logs.view',
        ]],
        'head_teacher' => ['scope' => 'school', 'label' => 'Head Teacher', 'permissions' => [
            'students.view', 'teachers.view', 'classes.view', 'subjects.view', 'attendance.view',
            'attendance.mark', 'exams.view', 'marks.view', 'marks.manage', 'reports.view',
        ]],
        'teacher' => ['scope' => 'school', 'label' => 'Teacher', 'permissions' => [
            'students.view', 'classes.view', 'subjects.view', 'attendance.view', 'attendance.mark',
            'exams.view', 'marks.view', 'marks.manage', 'reports.view',
        ]],
        'accountant' => ['scope' => 'school', 'label' => 'Accountant', 'permissions' => [
            'students.view', 'fees.view', 'fees.manage', 'payments.record', 'reports.view',
        ]],
        'bursar' => ['scope' => 'school', 'label' => 'Bursar', 'permissions' => [
            'students.view', 'fees.view', 'fees.manage', 'payments.record', 'reports.view',
        ]],
        'librarian' => ['scope' => 'school', 'label' => 'Librarian', 'permissions' => ['students.view', 'library.manage']],
        'parent' => ['scope' => 'school', 'label' => 'Parent', 'permissions' => []],
        'student' => ['scope' => 'school', 'label' => 'Student', 'permissions' => []],
        'staff' => ['scope' => 'school', 'label' => 'Other Staff', 'permissions' => ['attendance.view']],
    ];

    public function up()
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 50)->unique();
            $table->string('label', 100);
            $table->string('scope', 20);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100)->unique();
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->unsignedInteger('role_id');
            $table->unsignedInteger('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('granted_by')->nullable();
            $table->boolean('allowed')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $table->foreign('granted_by')->references('id')->on('users')->onDelete('set null');
        });

        $now = now();
        foreach ($this->permissions as $name => $description) {
            DB::table('permissions')->insert(['name' => $name, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach ($this->roles as $name => $role) {
            DB::table('roles')->insert(['name' => $name, 'label' => $role['label'], 'scope' => $role['scope'], 'created_at' => $now, 'updated_at' => $now]);
        }

        $schoolPermissions = array_values(array_filter(array_keys($this->permissions), function ($name) {
            return strpos($name, 'platform.') !== 0;
        }));
        foreach ($this->roles as $name => $role) {
            $grants = $role['permissions'] === 'all_school' ? $schoolPermissions : $role['permissions'];
            foreach ($grants as $permission) {
                DB::table('role_permissions')->insert([
                    'role_id' => DB::table('roles')->where('name', $name)->value('id'),
                    'permission_id' => DB::table('permissions')->where('name', $permission)->value('id'),
                ]);
            }
        }

        $types = [
            ['title' => 'principal', 'name' => 'Principal', 'level' => 3],
            ['title' => 'head_teacher', 'name' => 'Head Teacher', 'level' => 3],
            ['title' => 'bursar', 'name' => 'Bursar', 'level' => 5],
            ['title' => 'librarian', 'name' => 'Librarian', 'level' => 6],
            ['title' => 'staff', 'name' => 'Other Staff', 'level' => 6],
        ];
        foreach ($types as $type) {
            if (!DB::table('user_types')->where('title', $type['title'])->exists()) {
                DB::table('user_types')->insert($type + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
}
