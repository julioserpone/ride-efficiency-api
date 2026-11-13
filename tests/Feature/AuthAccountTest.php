<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('change password requires correct current password', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/password', [
        'current_password' => 'wrong',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertStatus(422);
});

test('change password succeeds and sets password_changed_at', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/password', [
        'current_password' => 'old-password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    $user->refresh();
    expect(Hash::check('new-password-123', $user->password))->toBeTrue();
    expect($user->password_changed_at)->not->toBeNull();
});

test('sessions list and revoke', function () {
    $user = User::factory()->create();
    $tokenA = $user->createToken('device-a');
    $tokenB = $user->createToken('device-b');
    $current = $user->createToken('current');

    $this->withToken($current->plainTextToken)
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->assertJsonCount(3, 'sessions');

    $this->withToken($current->plainTextToken)
        ->deleteJson('/api/v1/auth/sessions/'.$tokenA->accessToken->id)
        ->assertOk();

    expect($user->tokens()->count())->toBe(2);
});

test('show includes password rotation flag', function () {
    $user = User::factory()->create(['password_changed_at' => now()->subDays(200)]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/auth/user')
        ->assertOk()
        ->assertJsonPath('password_rotation_due', true);
});
