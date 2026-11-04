<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Lga;
use App\Models\Nationality;
use App\Models\State;
use App\Models\UserType;
use App\Tenancy\TenantContext;
use App\User;
use Database\Seeders\UgandaLocationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserLocationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->seed(UgandaLocationsSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_country_and_complete_uganda_location_seed_is_additive_and_idempotent()
    {
        $legacyNationalityId = DB::table('nationalities')->insertGetId(['name' => 'Legacy Nationality', 'created_at' => now(), 'updated_at' => now()]);
        $legacyStateId = DB::table('states')->insertGetId(['name' => 'Legacy State', 'created_at' => now(), 'updated_at' => now()]);
        $legacyDistrictId = DB::table('lgas')->insertGetId(['state_id' => $legacyStateId, 'name' => 'Legacy District', 'created_at' => now(), 'updated_at' => now()]);
        $before = [$legacyNationalityId, $legacyStateId, $legacyDistrictId];

        $this->seed(UgandaLocationsSeeder::class);

        $this->assertContains('Uganda', config('locations.countries'));
        foreach (['Kenya', 'Tanzania', 'Rwanda', 'Burundi', 'South Sudan', 'Democratic Republic of the Congo', 'Ethiopia', 'Somalia', 'Nigeria', 'Ghana', 'South Africa', 'United Kingdom', 'United States', 'Canada', 'India', 'China'] as $country) {
            $this->assertDatabaseHas('nationalities', ['name' => $country]);
        }

        $regions = config('locations.uganda');
        $this->assertCount(4, $regions);
        $this->assertSame([26, 37, 37, 35], array_map('count', array_values($regions)));
        $this->assertSame(135, array_sum(array_map('count', array_values($regions))));
        foreach ($regions as $region => $districts) {
            $state = State::where('name', $region)->firstOrFail();
            $this->assertSame(count($districts), Lga::where('state_id', $state->id)->count());
            foreach ($districts as $district) {
                $this->assertDatabaseHas('lgas', ['state_id' => $state->id, 'name' => $district]);
            }
        }

        $this->assertDatabaseHas('nationalities', ['id' => $before[0], 'name' => 'Legacy Nationality']);
        $this->assertDatabaseHas('states', ['id' => $before[1], 'name' => 'Legacy State']);
        $this->assertDatabaseHas('lgas', ['id' => $before[2], 'state_id' => $before[1], 'name' => 'Legacy District']);
    }

    public function test_create_form_defaults_to_uganda_and_ajax_returns_only_the_selected_region_districts()
    {
        $admin = $this->admin();
        $ugandaId = Nationality::where('name', 'Uganda')->value('id');

        $response = $this->actingAs($admin)->get('/users');
        $response->assertOk()->assertViewHas('default_nationality_id', $ugandaId);
        $response->assertSee('selected value="'.$ugandaId.'">Uganda', false);

        foreach (config('locations.uganda') as $region => $districts) {
            $stateId = State::where('name', $region)->value('id');
            $names = array_column($this->actingAs($admin)->get('/ajax/get_lga/'.$stateId)->assertOk()->json(), 'name');
            sort($districts);
            $this->assertSame($districts, $names, $region.' district options');
        }
    }

    public function test_edit_form_and_update_preserve_existing_uganda_and_legacy_location_values()
    {
        $schoolId = $this->school();
        $admin = $this->admin($schoolId);
        $uganda = Nationality::where('name', 'Uganda')->firstOrFail();
        $central = State::where('name', 'Central')->firstOrFail();
        $kampala = Lga::where('state_id', $central->id)->where('name', 'Kampala')->firstOrFail();
        $ugandanId = DB::table('nationalities')->insertGetId(['name' => 'Ugandan', 'created_at' => now(), 'updated_at' => now()]);
        $legacyStateId = DB::table('states')->insertGetId(['name' => 'Legacy Nigerian State', 'created_at' => now(), 'updated_at' => now()]);
        $legacyDistrictId = DB::table('lgas')->insertGetId(['state_id' => $legacyStateId, 'name' => 'Legacy Nigerian District', 'created_at' => now(), 'updated_at' => now()]);
        $ugandan = Nationality::findOrFail($ugandanId);
        $legacyState = State::findOrFail($legacyStateId);
        $legacyDistrict = Lga::findOrFail($legacyDistrictId);
        $ugandaUser = $this->user($schoolId, 'uganda-user', $uganda->id, $central->id, $kampala->id);
        $legacyUser = $this->user($schoolId, 'legacy-user', $ugandan->id, $legacyState->id, $legacyDistrict->id);

        $this->actingAs($admin)->get('/users/'.Qs::hash($ugandaUser->id).'/edit')
            ->assertOk()
            ->assertSee('selected value="'.$uganda->id.'">Uganda', false)
            ->assertSee('selected value="'.$central->id.'">Central', false)
            ->assertSee('selected value="'.$kampala->id.'">Kampala', false);

        $this->actingAs($admin)->get('/users/'.Qs::hash($legacyUser->id).'/edit')
            ->assertOk()
            ->assertSee('selected value="'.$ugandan->id.'">Ugandan', false)
            ->assertSee('selected value="'.$legacyState->id.'">Legacy Nigerian State', false)
            ->assertSee('selected value="'.$legacyDistrict->id.'">Legacy Nigerian District', false);

        $this->actingAs($admin)->put('/users/'.Qs::hash($legacyUser->id), [
            'name' => 'Legacy User Preserved', 'gender' => 'Female', 'address' => 'Legacy saved address',
            'nal_id' => $ugandan->id, 'state_id' => $legacyState->id, 'lga_id' => $legacyDistrict->id,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $legacyUser->id, 'nal_id' => $ugandan->id,
            'state_id' => $legacyState->id, 'lga_id' => $legacyDistrict->id,
        ]);
    }

    public function test_new_user_can_save_uganda_region_and_matching_district_but_not_a_foreign_district()
    {
        $schoolId = $this->school();
        $admin = $this->admin($schoolId);
        $type = UserType::forceCreate(['title' => 'teacher', 'name' => 'Teacher', 'level' => '4']);
        $ugandaId = Nationality::where('name', 'Uganda')->value('id');
        $eastern = State::where('name', 'Eastern')->firstOrFail();
        $jinja = Lga::where('state_id', $eastern->id)->where('name', 'Jinja')->firstOrFail();
        $central = State::where('name', 'Central')->firstOrFail();
        $kampala = Lga::where('state_id', $central->id)->where('name', 'Kampala')->firstOrFail();
        $payload = [
            'name' => 'New Uganda Teacher', 'gender' => 'Female', 'address' => 'Kampala local address',
            'email' => 'uganda-teacher@locations.test', 'username' => 'uganda-teacher',
            'user_type' => Qs::hash($type->id), 'nal_id' => $ugandaId,
            'state_id' => $eastern->id, 'lga_id' => $jinja->id,
        ];

        $this->actingAs($admin)->postJson('/users', array_merge($payload, ['lga_id' => $kampala->id]))
            ->assertStatus(422)->assertJsonValidationErrors('lga_id');

        $this->actingAs($admin)->postJson('/users', $payload)->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('users', [
            'email' => 'uganda-teacher@locations.test', 'school_id' => $schoolId,
            'nal_id' => $ugandaId, 'state_id' => $eastern->id, 'lga_id' => $jinja->id,
        ]);
    }

    private function admin($schoolId = null)
    {
        $schoolId = $schoolId ?: $this->school();
        app(TenantContext::class)->setSchoolId($schoolId);

        return User::create([
            'name' => 'Location Test Admin', 'email' => 'location-admin-'.$schoolId.'@test.local',
            'username' => 'location-admin-'.$schoolId, 'code' => 'LOCATION-ADMIN-'.$schoolId,
            'user_type' => 'admin', 'password' => Hash::make('LocationTest!'), 'school_id' => $schoolId,
        ]);
    }

    private function user($schoolId, $suffix, $nationalityId, $stateId, $districtId)
    {
        app(TenantContext::class)->setSchoolId($schoolId);

        return User::create([
            'name' => 'Location '.$suffix, 'email' => $suffix.'@location.test',
            'username' => $suffix, 'code' => strtoupper('LOC-'.$suffix), 'user_type' => 'staff',
            'password' => Hash::make('LocationTest!'), 'school_id' => $schoolId,
            'gender' => 'Female', 'address' => 'Location test address', 'nal_id' => $nationalityId,
            'state_id' => $stateId, 'lga_id' => $districtId,
        ]);
    }

    private function school()
    {
        return DB::table('schools')->insertGetId([
            'name' => 'Location Test School', 'code' => 'LOCATION-SCHOOL-'.strtoupper(substr(uniqid(), -6)),
            'status' => 'active', 'timezone' => 'UTC', 'currency' => 'UGX',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
