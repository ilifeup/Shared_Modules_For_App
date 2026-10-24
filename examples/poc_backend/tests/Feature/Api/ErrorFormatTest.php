<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('renders unknown api routes as NOT_FOUND with request id', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

    expect($response->json('error.request_id'))
        ->toBe($response->headers->get('X-Request-Id'));
});

it('renders validation errors with fields', function () {
    Route::post('/api/v1/_test/validate', fn (Request $request) => $request->validate([
        'email' => ['required', 'email'],
    ]));

    $this->postJson('/api/v1/_test/validate', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['email'], 'request_id']]);
});

it('renders unauthenticated requests as 401', function () {
    Route::get('/api/v1/_test/protected', fn () => 'ok')->middleware('auth:sanctum');

    $this->getJson('/api/v1/_test/protected')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('hides internal exception messages', function () {
    Route::get('/api/v1/_test/boom', function () {
        throw new RuntimeException('secret-internal-detail');
    });

    $response = $this->getJson('/api/v1/_test/boom');

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'SERVER_ERROR');

    expect($response->getContent())->not->toContain('secret-internal-detail');
});
