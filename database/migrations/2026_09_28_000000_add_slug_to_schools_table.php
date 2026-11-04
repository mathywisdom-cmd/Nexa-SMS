<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddSlugToSchoolsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('schools', 'slug')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->string('slug')->nullable();
            });
        }

        $schools = DB::table('schools')->orderBy('id')->get(['id', 'name', 'slug']);
        $reserved = [];
        foreach ($schools as $school) {
            $existing = (string) $school->slug;
            if ($this->isValidSlug($existing)) {
                $reserved[$existing] = true;
            }
        }

        $used = [];
        foreach ($schools as $school) {
            $existing = (string) $school->slug;
            if ($this->isValidSlug($existing) && !isset($used[$existing])) {
                $slug = $existing;
            } else {
                $base = Str::slug($school->name) ?: 'school';
                $slug = $base;
                $suffix = 2;
                while (isset($used[$slug]) || isset($reserved[$slug])) {
                    $slug = $base.'-'.$suffix++;
                }

                DB::table('schools')->where('id', $school->id)->update(['slug' => $slug]);
            }

            $used[$slug] = true;
        }

        Schema::table('schools', function (Blueprint $table) {
            $table->unique('slug', 'schools_slug_unique');
        });
    }

    public function down()
    {
        if (Schema::hasColumn('schools', 'slug')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->dropUnique('schools_slug_unique');
                $table->dropColumn('slug');
            });
        }
    }

    private function isValidSlug($slug)
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }
}
