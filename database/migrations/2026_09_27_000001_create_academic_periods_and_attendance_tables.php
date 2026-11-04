<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAcademicPeriodsAndAttendanceTables extends Migration
{
    public function up()
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('school_id');
            $table->string('name', 40);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'id']);
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('school_id');
            $table->unsignedInteger('academic_year_id');
            $table->string('name', 40);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'academic_year_id', 'name']);
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
            $table->foreign(['school_id', 'academic_year_id'])
                ->references(['school_id', 'id'])->on('academic_years')->onDelete('cascade');
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('school_id');
            $table->unsignedInteger('my_class_id');
            $table->unsignedInteger('section_id');
            $table->date('attendance_date');
            $table->unsignedInteger('academic_year_id')->nullable();
            $table->unsignedInteger('term_id')->nullable();
            $table->unsignedInteger('marked_by')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'my_class_id', 'section_id', 'attendance_date'], 'attendance_class_section_date_unique');
            $table->index(['school_id', 'attendance_date']);
            $table->index(['school_id', 'academic_year_id', 'term_id']);
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
            $table->foreign('my_class_id')->references('id')->on('my_classes')->onDelete('cascade');
            $table->foreign('section_id')->references('id')->on('sections')->onDelete('cascade');
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->onDelete('set null');
            $table->foreign('term_id')->references('id')->on('terms')->onDelete('set null');
            $table->foreign('marked_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('school_id');
            $table->unsignedInteger('attendance_session_id');
            $table->unsignedInteger('student_id');
            $table->string('status', 12);
            $table->string('remarks')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'attendance_session_id', 'student_id'], 'attendance_record_student_unique');
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
            $table->foreign('attendance_session_id')->references('id')->on('attendance_sessions')->onDelete('cascade');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_years');
    }
}
