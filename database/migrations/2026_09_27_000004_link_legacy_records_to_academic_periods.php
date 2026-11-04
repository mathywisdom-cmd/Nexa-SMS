<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LinkLegacyRecordsToAcademicPeriods extends Migration
{
    protected $yearColumns = [
        'student_records' => 'session', 'exams' => 'year', 'marks' => 'year', 'exam_records' => 'year',
        'payments' => 'year', 'payment_records' => 'year', 'receipts' => 'year', 'time_table_records' => 'year',
    ];

    public function up()
    {
        foreach ($this->yearColumns as $table => $column) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (!Schema::hasColumn($table, 'academic_year_id')) $blueprint->unsignedInteger('academic_year_id')->nullable()->index();
                if (in_array($table, ['exams', 'marks', 'exam_records', 'time_table_records'], true)
                    && !Schema::hasColumn($table, 'term_id')) $blueprint->unsignedInteger('term_id')->nullable()->index();
            });
        }
        if (Schema::hasTable('promotions')) {
            Schema::table('promotions', function (Blueprint $blueprint) {
                if (!Schema::hasColumn('promotions', 'from_academic_year_id')) $blueprint->unsignedInteger('from_academic_year_id')->nullable()->index();
                if (!Schema::hasColumn('promotions', 'to_academic_year_id')) $blueprint->unsignedInteger('to_academic_year_id')->nullable()->index();
            });
        }

        $years = [];
        foreach ($this->yearColumns as $table => $column) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'school_id')) continue;
            foreach (DB::table($table)->whereNotNull('school_id')->whereNotNull($column)->select('school_id', $column)->distinct()->get() as $row) {
                $name = trim((string) $row->{$column});
                if ($name !== '' && strlen($name) <= 40) $years[(int) $row->school_id][$name] = true;
            }
        }
        if (Schema::hasTable('promotions')) {
            foreach (['from_session', 'to_session'] as $column) {
                foreach (DB::table('promotions')->whereNotNull('school_id')->whereNotNull($column)->select('school_id', $column)->distinct()->get() as $row) {
                    $name = trim((string) $row->{$column});
                    if ($name !== '' && strlen($name) <= 40) $years[(int) $row->school_id][$name] = true;
                }
            }
        }

        $now = now();
        foreach ($years as $schoolId => $names) {
            foreach (array_keys($names) as $name) {
                $this->ensurePeriod('academic_years', ['school_id' => $schoolId, 'name' => $name], $now);
            }
        }

        foreach ($this->yearColumns as $table => $column) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'school_id')) continue;
            foreach (DB::table($table)->whereNull('academic_year_id')->whereNotNull('school_id')->whereNotNull($column)->orderBy('id')->get() as $row) {
                $name = trim((string) $row->{$column});
                if ($name === '' || strlen($name) > 40) continue;
                $yearId = DB::table('academic_years')->where('school_id', $row->school_id)->where('name', $name)->value('id');
                if (!$yearId) continue;
                $values = ['academic_year_id' => $yearId];
                if (in_array($table, ['exams', 'marks', 'exam_records', 'time_table_records'], true)) {
                    $examId = $table === 'exams' ? $row->id : (isset($row->exam_id) ? $row->exam_id : null);
                    if ($examId) {
                        $exam = DB::table('exams')->where('school_id', $row->school_id)->where('id', $examId)->first();
                        // A linked term is only meaningful when the legacy record's
                        // year agrees with the exam's year. Keep term_id null for
                        // inconsistent legacy rows instead of attaching the exam's
                        // term to a different academic year.
                        if ($exam && trim((string) $exam->year) === $name) {
                            $termName = 'Term '.(int) $exam->term;
                            $this->ensurePeriod('terms', ['school_id' => $row->school_id, 'academic_year_id' => $yearId, 'name' => $termName], $now);
                            $values['term_id'] = DB::table('terms')->where('school_id', $row->school_id)->where('academic_year_id', $yearId)->where('name', $termName)->value('id');
                        }
                    }
                }
                DB::table($table)->where('id', $row->id)->update($values);
            }
        }

        if (Schema::hasTable('promotions')) {
            foreach (DB::table('promotions')->whereNotNull('school_id')->get() as $promotion) {
                foreach (['from_session' => 'from_academic_year_id', 'to_session' => 'to_academic_year_id'] as $legacy => $target) {
                    $name = trim((string) $promotion->{$legacy});
                    $yearId = $name !== '' && strlen($name) <= 40 ? DB::table('academic_years')->where('school_id', $promotion->school_id)->where('name', $name)->value('id') : null;
                    if ($yearId) DB::table('promotions')->where('id', $promotion->id)->update([$target => $yearId]);
                }
            }
        }
    }

    protected function ensurePeriod($table, array $identity, $now)
    {
        if (DB::table($table)->where($identity)->exists()) return;
        DB::table($table)->insert($identity + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function down()
    {
        foreach ($this->yearColumns as $table => $column) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $drop = [];
                if (Schema::hasColumn($table, 'academic_year_id')) $drop[] = 'academic_year_id';
                if (in_array($table, ['exams', 'marks', 'exam_records', 'time_table_records'], true) && Schema::hasColumn($table, 'term_id')) $drop[] = 'term_id';
                if ($drop) $blueprint->dropColumn($drop);
            });
        }
        if (Schema::hasTable('promotions')) {
            Schema::table('promotions', function (Blueprint $blueprint) {
                $drop = [];
                if (Schema::hasColumn('promotions', 'from_academic_year_id')) $drop[] = 'from_academic_year_id';
                if (Schema::hasColumn('promotions', 'to_academic_year_id')) $drop[] = 'to_academic_year_id';
                if ($drop) $blueprint->dropColumn($drop);
            });
        }
    }
}
