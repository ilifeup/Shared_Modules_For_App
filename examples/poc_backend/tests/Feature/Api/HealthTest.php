<?php

it('reports api and database status', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.database', 'ok')
        ->assertJsonStructure(['data' => ['status', 'database', 'server_time']])
        ->assertHeader('X-Request-Id');
});

it('echoes a valid incoming request id', function () {
    $this->withHeaders(['X-Request-Id' => 'app-req-12345678'])
        ->getJson('/api/v1/health')
        ->assertOk()
        ->assertHeader('X-Request-Id', 'app-req-12345678');
});

it('replaces an invalid incoming request id', function () {
    $response = $this->withHeaders(['X-Request-Id' => 'bad id!'])
        ->getJson('/api/v1/health');

    $response->assertOk();

    expect($response->headers->get('X-Request-Id'))
        ->not->toBe('bad id!')
        ->toMatch('/^[0-9a-f\-]{36}$/');
});
