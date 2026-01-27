<?php

declare(strict_types=1);

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\UserToken;

test('can authenticate with valid bearer token', function () {
    $user = User::factory()->create();
    $result = $user->createToken('Test Token');

    // Test the guard directly by invoking it with a mock request
    $guard = new \Core\Mod\Api\Guards\AccessTokenGuard(app('auth'));
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->headers->set('Authorization', "Bearer {$result['token']}");

    $authenticatedUser = $guard($request);

    expect($authenticatedUser)->not->toBeNull();
    expect($authenticatedUser->id)->toBe($user->id);
});

test('cannot authenticate with invalid token', function () {
    $response = $this->getJson('/api/v1/social/posts', [
        'Authorization' => 'Bearer invalid-token-that-does-not-exist',
    ]);

    $response->assertUnauthorized();
});

test('cannot authenticate with expired token', function () {
    $user = User::factory()->create();
    $token = UserToken::factory()
        ->for($user)
        ->expired()
        ->withToken('expired-token-12345')
        ->create();

    $response = $this->getJson('/api/v1/social/posts', [
        'Authorization' => 'Bearer expired-token-12345',
    ]);

    $response->assertUnauthorized();
});

test('cannot authenticate without authorization header', function () {
    $response = $this->getJson('/api/v1/social/posts');

    $response->assertUnauthorized();
});

test('token last_used_at is updated on successful authentication', function () {
    $user = User::factory()->create();
    $result = $user->createToken('Test Token');
    $tokenModel = $result['model'];

    expect($tokenModel->last_used_at)->toBeNull();

    // Test the guard directly by invoking it with a mock request
    $guard = new \Core\Mod\Api\Guards\AccessTokenGuard(app('auth'));
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->headers->set('Authorization', "Bearer {$result['token']}");

    $guard($request);

    // Refresh the token model and check last_used_at was updated
    $tokenModel->refresh();
    expect($tokenModel->last_used_at)->not->toBeNull();
    expect($tokenModel->last_used_at->timestamp)->toBeGreaterThan(now()->subMinute()->timestamp);
});

test('user can create multiple tokens with different names', function () {
    $user = User::factory()->create();

    $token1 = $user->createToken('Mobile App');
    $token2 = $user->createToken('Web Dashboard');
    $token3 = $user->createToken('CI/CD Pipeline');

    expect($user->tokens)->toHaveCount(3);
    expect($user->tokens->pluck('name')->toArray())->toBe([
        'Mobile App',
        'Web Dashboard',
        'CI/CD Pipeline',
    ]);
});

test('user can revoke a specific token', function () {
    $user = User::factory()->create();

    $token1 = $user->createToken('Token 1');
    $token2 = $user->createToken('Token 2');

    expect($user->tokens)->toHaveCount(2);

    $user->revokeToken($token1['model']->id);

    $user->refresh();
    expect($user->tokens)->toHaveCount(1);
    expect($user->tokens->first()->name)->toBe('Token 2');
});

test('user can revoke all tokens', function () {
    $user = User::factory()->create();

    $user->createToken('Token 1');
    $user->createToken('Token 2');
    $user->createToken('Token 3');

    expect($user->tokens)->toHaveCount(3);

    $user->revokeAllTokens();

    $user->refresh();
    expect($user->tokens)->toHaveCount(0);
});

test('tokens are automatically deleted when user is deleted', function () {
    $user = User::factory()->create();
    $result = $user->createToken('Test Token');
    $tokenId = $result['model']->id;

    expect(UserToken::find($tokenId))->not->toBeNull();

    $user->delete();

    // Token should be deleted due to CASCADE constraint
    expect(UserToken::find($tokenId))->toBeNull();
});

test('tokens are stored as hashed values', function () {
    $user = User::factory()->create();
    $result = $user->createToken('Test Token');
    $plainToken = $result['token'];
    $tokenModel = $result['model'];

    // The stored token should NOT match the plain text token
    expect($tokenModel->token)->not->toBe($plainToken);

    // But it should match the SHA-256 hash
    expect($tokenModel->token)->toBe(hash('sha256', $plainToken));
});

test('can create token with expiry date', function () {
    $user = User::factory()->create();
    $expiryDate = now()->addDays(30);

    $result = $user->createToken('Temporary Token', $expiryDate);
    $tokenModel = $result['model'];

    expect($tokenModel->expires_at)->not->toBeNull();
    expect($tokenModel->expires_at->timestamp)->toBe($expiryDate->timestamp);
    expect($tokenModel->isValid())->toBeTrue();
    expect($tokenModel->isExpired())->toBeFalse();
});

test('expired tokens are marked as invalid', function () {
    $token = UserToken::factory()
        ->expired()
        ->create();

    expect($token->isExpired())->toBeTrue();
    expect($token->isValid())->toBeFalse();
});

test('non-expired tokens are marked as valid', function () {
    $token = UserToken::factory()
        ->expiresIn(30)
        ->create();

    expect($token->isExpired())->toBeFalse();
    expect($token->isValid())->toBeTrue();
});

test('tokens without expiry date are always valid', function () {
    $token = UserToken::factory()->create();

    expect($token->expires_at)->toBeNull();
    expect($token->isExpired())->toBeFalse();
    expect($token->isValid())->toBeTrue();
});
