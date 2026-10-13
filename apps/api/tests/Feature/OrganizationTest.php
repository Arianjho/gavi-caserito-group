<?php

declare(strict_types=1);

use App\Modules\Platform\Application\ProvisionAccount;
use App\Shared\Rules\PeruvianRuc;

it('valida el digito verificador del RUC', function (): void {
    expect(PeruvianRuc::isValid('20601234565'))->toBeTrue()
        ->and(PeruvianRuc::isValid('20601234560'))->toBeFalse()
        ->and(PeruvianRuc::isValid('1234'))->toBeFalse();
});

it('crea empresa, sucursal y almacen y respeta la jerarquia', function (): void {
    $token = ownerToken();

    $company = $this->withToken($token)->postJson('/api/v1/companies', [
        'legal_name' => 'Jugueteria Mi Mundo SAC', 'tax_id' => '20601234565',
    ])->assertCreated()->json('data');

    $branch = $this->withToken($token)->postJson('/api/v1/branches', [
        'company_id' => $company['id'], 'code' => '0000', 'name' => 'Principal', 'is_main' => true,
    ])->assertCreated()->json('data');

    $this->withToken($token)->postJson('/api/v1/warehouses', [
        'branch_id' => $branch['id'], 'name' => 'Tienda',
    ])->assertCreated()->assertJsonPath('data.company_id', $company['id']);

    // La empresa tiene dependencias: no se elimina pero se ofrece desactivar.
    $this->withToken($token)->deleteJson('/api/v1/companies/'.$company['id'])
        ->assertStatus(409)->assertJsonPath('can_deactivate', true);
});

it('rechaza RUC invalido y duplicado', function (): void {
    $token = ownerToken();

    $this->withToken($token)->postJson('/api/v1/companies', ['legal_name' => 'X', 'tax_id' => '20601234560'])
        ->assertStatus(422)->assertJsonValidationErrors('tax_id');

    $this->withToken($token)->postJson('/api/v1/companies', ['legal_name' => 'A', 'tax_id' => '20601234565'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/companies', ['legal_name' => 'B', 'tax_id' => '20601234565'])
        ->assertStatus(422);
});

it('no muestra empresas de otra cuenta', function (): void {
    $tokenA = ownerToken();
    $this->withToken($tokenA)->postJson('/api/v1/companies', ['legal_name' => 'A', 'tax_id' => '20601234565'])->assertCreated();

    app(ProvisionAccount::class)->handle('Cuenta B', 'Otro', 'b@example.test', 'clave-12345');
    $tokenB = loginToken('b@example.test', 'clave-12345');

    $this->withToken($tokenB)->getJson('/api/v1/companies')->assertOk()->assertJsonCount(0, 'data');
});

it('un reintento con la misma llave no duplica y devuelve el mismo resultado', function (): void {
    $token = ownerToken();
    $payload = ['legal_name' => 'A', 'tax_id' => '20601234565'];

    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'k-1')
        ->postJson('/api/v1/companies', $payload)->assertCreated();
    $second = $this->withToken($token)->withHeader('Idempotency-Key', 'k-1')
        ->postJson('/api/v1/companies', $payload)->assertCreated()->assertHeader('Idempotent-Replay', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    $this->withToken($token)->getJson('/api/v1/companies')->assertJsonCount(1, 'data');

    $this->withToken($token)->withHeader('Idempotency-Key', 'k-1')
        ->postJson('/api/v1/companies', ['legal_name' => 'Otra', 'tax_id' => '20512345671'])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
});
