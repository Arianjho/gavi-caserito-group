<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Prepara empresa, sucursal, almacén y producto; devuelve sus identificadores.
 *
 * @param string $token Token del propietario.
 * @return array{warehouse: string, variant: string}
 */
function stockFixture(string $token): array
{
    $company = test()->withToken($token)->postJson('/api/v1/companies', [
        'legal_name' => 'Jugueteria SAC', 'tax_id' => '20601234565',
    ])->json('data.id');
    $branch = test()->withToken($token)->postJson('/api/v1/branches', [
        'company_id' => $company, 'code' => '0000', 'name' => 'Principal',
    ])->json('data.id');
    $warehouse = test()->withToken($token)->postJson('/api/v1/warehouses', [
        'branch_id' => $branch, 'name' => 'Tienda',
    ])->json('data.id');
    $variant = test()->withToken($token)->postJson('/api/v1/items', ['name' => 'Carro'])
        ->json('data.variants.0.id');

    return ['warehouse' => $warehouse, 'variant' => $variant];
}

it('registra saldo inicial y calcula costo promedio', function (): void {
    $token = ownerToken();
    $f = stockFixture($token);

    $this->withToken($token)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $f['warehouse'], 'type' => 'opening',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 10, 'unit_cost' => 5]],
    ])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $f['warehouse'], 'type' => 'adjustment_in',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 10, 'unit_cost' => 7]],
    ])->assertCreated();

    $row = $this->withToken($token)->getJson('/api/v1/inventory/stock')->assertOk()->json('data.0');

    expect((float) $row['on_hand'])->toBe(20.0)->and((float) $row['avg_cost'])->toBe(6.0);
});

it('rechaza una salida sin stock y no aplica nada', function (): void {
    $token = ownerToken();
    $f = stockFixture($token);

    $this->withToken($token)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $f['warehouse'], 'type' => 'opening',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 3, 'unit_cost' => 5]],
    ])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $f['warehouse'], 'type' => 'adjustment_out',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 5]],
    ])->assertStatus(422)->assertJsonPath('code', 'stock_insufficient');

    $row = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $row['on_hand'])->toBe(3.0);
});

it('el kardex lista los movimientos y es inmutable', function (): void {
    $token = ownerToken();
    $f = stockFixture($token);

    $this->withToken($token)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $f['warehouse'], 'type' => 'opening', 'reason' => 'Carga inicial',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 4, 'unit_cost' => 2]],
    ])->assertCreated();

    $this->withToken($token)->getJson('/api/v1/inventory/movements?variant_id='.$f['variant'])
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Carga inicial');

    $intento = fn () => DB::transaction(fn () => DB::table('inv.stock_movements')->update(['qty' => 99]));
    $asProvider = fn () => TenantContext::asProvider($intento);
    expect($asProvider)->toThrow(QueryException::class);
});
