<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Tenancy\TenantContext;
use App\User;
use Database\Seeders\SupportedUserTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAccountTypeOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $this->seed(SupportedUserTypesSeeder::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_school_user_form_offers_only_the_supported_school_account_types()
    {
        $schoolId = $this->school();
        $admin = $this->admin($schoolId);
        $legacyRoleId = DB::table('user_types')->insertGetId([
            'title' => 'principal', 'name' => 'Principal', 'level' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/users');
        $response->assertOk()->assertViewHas('creatable_user_types', function ($types) {
            return $types->pluck('title')->all() === config('user_types.school_creatable');
        });

        preg_match('/<select[^>]*id="user_type"[^>]*>(.*?)<\/select>/s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches);
        foreach (['Principal', 'Head Teacher', 'Bursar', 'Other Staff'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $matches[1]);
        }
        foreach (['Librarian', 'Accountant', 'Teacher', 'Student', 'Parent'] as $included) {
            $this->assertStringContainsString($included, $matches[1]);
        }
        $this->assertDatabaseHas('user_types', ['id' => $legacyRoleId, 'title' => 'principal']);
    }

    public function test_legacy_role_cannot_be_created_through_the_school_user_form()
    {
        $schoolId = $this->school();
        $admin = $this->admin($schoolId);
        $principalId = DB::table('user_types')->insertGetId([
            'title' => 'principal', 'name' => 'Principal', 'level' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $nationalityId = DB::table('nationalities')->insertGetId(['name' => 'Uganda', 'created_at' => now(), 'updated_at' => now()]);
        $stateId = DB::table('states')->insertGetId(['name' => 'Central', 'created_at' => now(), 'updated_at' => now()]);
        $districtId = DB::table('lgas')->insertGetId(['state_id' => $stateId, 'name' => 'Kampala', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->postJson('/users', [
            'name' => 'Rejected Principal Account', 'gender' => 'Female', 'address' => 'Local test address',
            'email' => 'legacy-principal@accounts.test', 'username' => 'legacy-principal',
            'user_type' => Qs::hash($principalId), 'nal_id' => $nationalityId,
            'state_id' => $stateId, 'lga_id' => $districtId,
        ])->assertStatus(422)->assertJsonValidationErrors('user_type');

        $this->assertDatabaseMissing('users', ['email' => 'legacy-principal@accounts.test']);
    }

    private function school()
    {
        return DB::table('schools')->insertGetId([
            'name' => 'Account Type School', 'code' => 'ACCOUNT-TYPE-SCHOOL', 'status' => 'active',
            'timezone' => 'UTC', 'currency' => 'UGX', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin($schoolId)
    {
        app(TenantContext::class)->setSchoolId($schoolId);
        return User::create([
            'name' => 'Account Type Admin', 'email' => 'account-types-admin@test.local',
            'username' => 'account-types-admin', 'code' => 'ACCOUNT-TYPE-ADMIN',
            'user_type' => 'admin', 'password' => Hash::make('AccountTypesTest!'), 'school_id' => $schoolId,
        ]);
    }
}
