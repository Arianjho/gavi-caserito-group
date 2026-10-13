<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Configura el canal y devuelve datos de una venta a un cliente con teléfono.
 *
 * @param string $token Token del propietario.
 * @param bool $optIn Si el cliente aceptó recibir WhatsApp.
 * @return array{sale: string, party: string}
 */
function notifyFixture(string $token, bool $optIn): array
{
    $f = orderFixture($token);
    test()->withToken($token)->putJson("/api/v1/customers/{$f['party']}", [
        'doc_type' => 'NONE', 'name' => 'Ana Torres', 'phone' => '987 654 321', 'whatsapp_opt_in' => $optIn,
    ])->assertOk();
    test()->withToken($token)->putJson('/api/v1/notifications/settings', [
        'base_url' => 'https://evo.example.test', 'instance' => 'mi-tienda', 'api_key' => 'secreta',
    ])->assertOk();
    $sale = test()->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 118]],
    ])->assertCreated()->json('data.id');

    return ['sale' => $sale, 'party' => $f['party']];
}

it('avisa el comprobante por WhatsApp a un cliente con consentimiento', function (): void {
    Http::fake(['evo.example.test/*' => Http::response(['key' => ['id' => 'ABC123']], 201)]);
    $token = ownerToken();
    $f = notifyFixture($token, true);

    $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/notify")->assertCreated();

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://evo.example.test/message/sendText/mi-tienda'
        && $r->hasHeader('apikey', 'secreta')
        && $r['number'] === '51987654321'
        && str_contains($r['text'], 'Ana'));
    $log = $this->withToken($token)->getJson('/api/v1/notifications')->json('data');
    expect($log[0]['status'])->toBe('sent');
});

it('no envia a un cliente sin consentimiento', function (): void {
    Http::fake();
    $token = ownerToken();
    $f = notifyFixture($token, false);

    $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/notify")
        ->assertStatus(422)->assertJsonPath('code', 'no_opt_in');
    Http::assertNothingSent();
});

it('un corte de WhatsApp deja el mensaje en cola sin afectar la venta', function (): void {
    Http::fake(['evo.example.test/*' => Http::response('', 503)]);
    $token = ownerToken();
    $f = notifyFixture($token, true);

    $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/notify")->assertCreated();

    $log = $this->withToken($token)->getJson('/api/v1/notifications')->json('data');
    expect($log[0]['status'])->toBe('queued')->and($log[0]['error'])->toContain('503');
});

it('rechaza un telefono que no es celular peruano', function (): void {
    Http::fake();
    $token = ownerToken();
    notifyFixture($token, true);

    $this->withToken($token)->postJson('/api/v1/notifications/test', ['phone' => '12345'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_phone');
});
