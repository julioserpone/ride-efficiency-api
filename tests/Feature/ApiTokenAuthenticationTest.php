<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a user can issue a personal access token with valid credentials', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    $response = $this->postJson(route('api.v1.auth.token.store'), [
        'email' => $user->email,
        'password' => 'secret-password',
        'device_name' => 'web-dashboard',
    ]);

    $response->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email']]);

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'web-dashboard',
    ]);
});

test('a token cannot be issued with invalid credentials', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    $response = $this->postJson(route('api.v1.auth.token.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('token issuance requires email and password', function () {
    $response = $this->postJson(route('api.v1.auth.token.store'), []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

test('protected endpoints reject unauthenticated requests', function () {
    $this->getJson(route('api.v1.stats.summary'))->assertUnauthorized();
    $this->getJson(route('api.v1.shifts.index'))->assertUnauthorized();
    $this->getJson(route('api.v1.auth.user'))->assertUnauthorized();
});

test('unauthenticated api requests never redirect to a login page', function () {
    // There is no web login route in this API-only service, so the guard must
    // answer 401 rather than blow up trying to build a redirect.
    $this->get(route('api.v1.stats.summary'))->assertUnauthorized();
});

test('the current user can be retrieved with a bearer token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('web-dashboard')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(route('api.v1.auth.user'));

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email);
});

test('a token can be revoked using its own credentials', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.auth.token.destroy'))
        ->assertOk()
        ->assertJsonPath('message', 'Token revoked successfully.');
});
