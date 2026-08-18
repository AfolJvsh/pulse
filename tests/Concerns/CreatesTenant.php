<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait CreatesTenant
{
    /** @return array{0:User,1:Organization} */
    protected function tenant(string $suffix = 'a'): array
    {
        $user = User::create([
            'name' => 'Engineer '.strtoupper($suffix),
            'email' => "engineer-{$suffix}-".Str::lower(Str::random(8)).'@example.test',
            'password' => 'password-for-tests',
        ]);
        $organization = Organization::create([
            'name' => 'Organization '.strtoupper($suffix),
            'slug' => 'org-'.$suffix.'-'.Str::lower(Str::random(8)),
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner']);
        return [$user, $organization];
    }

    protected function actingAsTenant(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }
}
