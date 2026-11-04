<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportedUserTypesSeeder extends Seeder
{
    /**
     * Add supported account types while retaining legacy role rows and their IDs.
     * Run explicitly with: php artisan db:seed --class=Database\\Seeders\\SupportedUserTypesSeeder
     *
     * @return void
     */
    public function run()
    {
        foreach (config('user_types.supported', []) as $title => $type) {
            $exists = DB::table('user_types')->where('title', $title)->exists();
            if (!$exists) {
                DB::table('user_types')->insert([
                    'title' => $title,
                    'name' => $type['name'],
                    'level' => $type['level'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
