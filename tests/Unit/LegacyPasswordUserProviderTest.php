<?php

namespace Tests\Unit;

use App\Auth\LegacyPasswordUserProvider;
use App\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyPasswordUserProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });
    }

    public function test_only_profiles_with_legacy_password_hashes_are_available_to_laravel_auth(): void
    {
        $legacy = User::create([
            'name' => 'Legacy User',
            'email' => 'legacy@example.test',
            'username' => 'legacy',
            'password' => Hash::make('legacy-password'),
        ]);
        $supabase = User::create([
            'name' => 'Supabase User',
            'email' => 'supabase@example.test',
            'username' => 'supabase',
            'password' => null,
        ]);

        $provider = new LegacyPasswordUserProvider(app('hash')->driver(), User::class);

        $this->assertSame($legacy->id, $provider->retrieveByCredentials(['email' => $legacy->email, 'password' => 'x'])->id);
        $this->assertNull($provider->retrieveByCredentials(['email' => $supabase->email, 'password' => 'x']));
        $this->assertSame($legacy->id, $provider->retrieveById($legacy->id)->id);
        $this->assertNull($provider->retrieveById($supabase->id));
    }
}
