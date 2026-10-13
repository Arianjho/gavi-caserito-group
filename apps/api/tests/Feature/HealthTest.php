<?php

declare(strict_types=1);

it('responde el estado de salud de la API', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'app', 'database', 'time']);
});

it('reporta que la base de datos es PostgreSQL', function () {
    $this->getJson('/api/v1/health')->assertJsonPath('database', 'pgsql');
});
