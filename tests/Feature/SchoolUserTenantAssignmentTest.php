<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Tenancy\TenantContext;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SchoolUserTenantAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    public function test_school_admin_cannot_move_an_existing_user_to_another_school_by_forging_school_id()
    {
        $schoolA = $this->school('User Assignment School A');
        $schoolB = $this->school('User Assignment School B');
        $admin = $this->user('admin', $schoolA, 'user-assignment-admin');
        $target = $this->user('teacher', $schoolA, 'user-assignment-target');
        $nationalityId = DB::table('nationalities')->insertGetId([
            'name' => 'Test Nationality', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stateId = DB::table('states')->insertGetId([
            'name' => 'Test Region', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $districtId = DB::table('lgas')->insertGetId([
            'state_id' => $stateId, 'name' => 'Test District', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->put('/users/'.Qs::hash($target->id), [
            'name' => 'Updated School Teacher',
            'gender' => 'Female',
            'address' => 'Updated local address',
            'state_id' => $stateId,
            'lga_id' => $districtId,
            'nal_id' => $nationalityId,
            'school_id' => $schoolB,
            'user_type' => 'super_admin',
        ])->assertOk();

        $updated = DB::table('users')->where('id', $target->id)->first();
        $this->assertSame($schoolA, (int) $updated->school_id);
        $this->assertSame('teacher', $updated->user_type);
    }

    public function test_school_user_creation_ignores_a_forged_school_id()
    {
        $schoolA = $this->school('User Create School A');
        $schoolB = $this->school('User Create School B');
        app(TenantContext::class)->setSchoolId($schoolA);

        $created = User::create([
            'name' => 'Created local teacher', 'email' => 'forged-create@tenant.test',
            'username' => 'forged-create@tenant.test', 'code' => 'FORGED-CREATE',
            'user_type' => 'teacher', 'password' => Hash::make('TenantAssignmentTest!'),
            'school_id' => $schoolB,
        ]);

        $this->assertSame($schoolA, (int) $created->school_id);
    }

    private function school($name)
    {
        return DB::table('schools')->insertGetId([
            'name' => $name, 'code' => strtoupper(str_replace(' ', '-', $name)), 'status' => 'active',
            'timezone' => 'UTC', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user($role, $schoolId, $suffix)
    {
        app(TenantContext::class)->setSchoolId($schoolId);
        return User::create([
            'name' => ucfirst($role).' User', 'email' => $suffix.'@tenant.test',
            'username' => $suffix.'@tenant.test', 'code' => strtoupper('TU-'.$suffix),
            'user_type' => $role, 'password' => Hash::make('TenantAssignmentTest!'), 'school_id' => $schoolId,
        ]);
    }
}
