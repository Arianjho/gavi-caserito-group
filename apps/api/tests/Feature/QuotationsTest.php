<?php

declare(strict_types=1);

/**
 * Crea una cotización de 2 unidades a 118 con la empresa de la cuenta.
 *
 * @param array{warehouse: string, variant: string, party: string} $f Datos base.
 * @param string $token Token del propietario.
 * @param array<string, mixed> $extra Campos que reemplazan a los de ejemplo.
 * @return array<string, mixed>
 */
function makeQuotation(array $f, string $token, array $extra = []): array
{
    $company = test()->withToken($token)->getJson('/api/v1/companies')->json('data.0.id');

    return test()->withToken($token)->postJson('/api/v1/quotations', $extra + [
        'company_id' => $company, 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 2, 'unit_price' => 118]],
    ])->assertCreated()->json('data');
}

it('crea una cotizacion sin reservar stock', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);

    $quotation = makeQuotation($f, $token);

    expect($quotation['total'])->toBe('236.00')->and($quotation['status'])->toBe('open')
        ->and($quotation['number'])->toBe(1);
    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['reserved'])->toBe(0.0);
});

it('convierte la cotizacion en pedido y reserva el stock', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $quotation = makeQuotation($f, $token);

    $order = $this->withToken($token)->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'warehouse_id' => $f['warehouse'],
    ])->assertCreated()->json('data');

    expect($order['total'])->toBe('236.00');
    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['reserved'])->toBe(2.0);

    $this->withToken($token)->getJson("/api/v1/quotations/{$quotation['id']}")
        ->assertJsonPath('data.status', 'converted')->assertJsonPath('data.order_id', $order['id']);
    $this->withToken($token)->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'warehouse_id' => $f['warehouse'],
    ])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
});

it('rechaza una cotizacion abierta y ya no se puede convertir', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $quotation = makeQuotation($f, $token);

    $this->withToken($token)->postJson("/api/v1/quotations/{$quotation['id']}/reject")
        ->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->withToken($token)->postJson("/api/v1/quotations/{$quotation['id']}/convert", [
        'warehouse_id' => $f['warehouse'],
    ])->assertStatus(409);
});
