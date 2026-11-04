<?php

namespace Database\Seeders;

use App\Models\Lga;
use App\Models\Nationality;
use App\Models\State;
use Illuminate\Database\Seeder;

class UgandaLocationsSeeder extends Seeder
{
    /**
     * Add Uganda form reference values without changing or deleting legacy data.
     * Run explicitly with: php artisan db:seed --class=Database\\Seeders\\UgandaLocationsSeeder
     * Do not replace the existing location seeders, which reset legacy values.
     *
     * @return void
     */
    public function run()
    {
        foreach (config('locations.countries', []) as $country) {
            Nationality::firstOrCreate(['name' => $country]);
        }

        foreach (config('locations.uganda', []) as $region => $districts) {
            $state = State::firstOrCreate(['name' => $region]);

            foreach ($districts as $district) {
                Lga::firstOrCreate([
                    'state_id' => $state->id,
                    'name' => $district,
                ]);
            }
        }
    }
}
