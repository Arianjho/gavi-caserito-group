<?php

declare(strict_types=1);

use App\Modules\Fiscal\Application\FiscalReconciler;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Configura eFact para la empresa del fixture y crea una venta de S/ 118.
 *
 * @param string $token Token del propietario.
 * @return array{sale: string, company: string, warehouse: string, variant: string}
 */
function fiscalFixture(string $token): array
{
    $f = saleFixture($token);
    $company = test()->withToken($token)->getJson('/api/v1/warehouses')->json('data.0.company_id');
    test()->withToken($token)->putJson("/api/v1/fiscal/settings/{$company}", [
        'base_url' => 'https://efact.test', 'environment' => 'beta', 'api_key' => 'key-123',
        'api_secret' => 'secret-456', 'webhook_secret' => 'whsec_test', 'boleta_series' => 'B001', 'invoice_series' => 'F001',
    ])->assertOk();
    $sale = test()->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'yape', 'amount' => 118]],
    ])->assertCreated()->json('data.id');

    return $f + ['sale' => $sale, 'company' => $company];
}

it('guarda la configuracion cifrada y no devuelve secretos', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);

    $settings = $this->withToken($token)->getJson("/api/v1/fiscal/settings/{$f['company']}")->assertOk()->json('data');
    expect($settings['has_credentials'])->toBeTrue()
        ->and($settings)->not->toHaveKey('api_secret')
        ->and(json_encode($settings))->not->toContain('secret-456');
});

it('emite una boleta y queda aceptada', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);
    Http::fake(['efact.test/*' => Http::response([
        'estado' => 'exito',
        'datos' => ['id' => 345, 'serie' => 'B001', 'correlativo' => '00000345', 'sunat' => ['estado' => 'aceptado', 'codigo' => '0', 'descripcion' => 'Aceptada']],
    ], 201)]);

    $doc = $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '03'])
        ->assertStatus(202)->json('data');

    expect($doc['status'])->toBe('accepted')->and($doc['number'])->toBe('00000345')->and($doc['external_id'])->toBe('caserito:'.$f['sale']);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'key-123')
        && $request['serie'] === 'B001' && $request['external_id'] === 'caserito:'.$f['sale']
        && $request['items'][0]['precio_unitario'] === 118.0);

    $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '03'])
        ->assertStatus(409)->assertJsonPath('code', 'already_emitted');
    $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/void", ['reason' => 'Error de venta'])
        ->assertStatus(409)->assertJsonPath('code', 'fiscal_document_issued');
});

it('un corte de eFact no bloquea la venta y se reintenta despues', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);
    Http::fake(['efact.test/*' => Http::sequence()
        ->push('boom', 503)
        ->push([
            'estado' => 'exito',
            'datos' => ['id' => 9, 'serie' => 'B001', 'correlativo' => '00000009', 'sunat' => ['estado' => 'enviado']],
        ], 200)]);

    $doc = $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '03'])
        ->assertStatus(202)->json('data');
    expect($doc['status'])->toBe('queued')->and($doc['attempts'])->toBe(1)->and($doc['last_error'])->toContain('503');

    $retried = $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$doc['id']}/retry")->assertOk()->json('data');
    expect($retried['status'])->toBe('pending')->and($retried['efact_id'])->toBe('9');
});

it('una factura exige cliente con RUC', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);

    $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '01'])
        ->assertStatus(422)->assertJsonPath('code', 'ruc_required');
});

it('el webhook valida firma, deduplica y actualiza el estado', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);
    Http::fake(['efact.test/*' => Http::response([
        'estado' => 'exito',
        'datos' => ['id' => 77, 'serie' => 'B001', 'correlativo' => '00000077', 'sunat' => ['estado' => 'enviado']],
    ], 201)]);
    $doc = $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '03'])->json('data');
    expect($doc['status'])->toBe('pending');

    $body = json_encode(['event_id' => 'e1', 'event' => 'document.sent', 'document' => [
        'id' => 77, 'sunat_status' => 'aceptado', 'sunat_code' => '0', 'sunat_description' => 'Aceptada',
    ]]);
    $ts = (string) time();
    $headers = [
        'X-Gavi-Event-Id' => 'evt-1', 'X-Gavi-Timestamp' => $ts,
        'X-Gavi-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'whsec_test'),
    ];
    $url = "/api/v1/webhooks/efact/{$f['company']}";

    $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']), $body)
        ->assertOk();
    $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']), $body)
        ->assertOk()->assertJsonPath('data.duplicate', true);

    $list = $this->withToken($token)->getJson('/api/v1/fiscal/documents')->json('data');
    expect($list[0]['status'])->toBe('accepted');

    $bad = $headers;
    $bad['X-Gavi-Signature'] = 'sha256=mala';
    $bad['X-Gavi-Event-Id'] = 'evt-2';
    $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars($bad + ['Content-Type' => 'application/json']), $body)
        ->assertStatus(401);
});

it('el reconciliador consulta los comprobantes pendientes y reenvia los que no salieron', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);
    Http::fake(['efact.test/*' => Http::sequence()
        ->push(['estado' => 'exito', 'datos' => ['id' => 55, 'serie' => 'B001', 'correlativo' => '00000055', 'sunat' => ['estado' => 'enviado']]], 201)
        ->push(['estado' => 'exito', 'datos' => ['id' => 55, 'serie' => 'B001', 'correlativo' => '00000055', 'sunat' => ['estado' => 'aceptado', 'codigo' => '0']]], 200)]);
    $doc = $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '03'])->json('data');
    expect($doc['status'])->toBe('pending');

    TenantContext::asProvider(fn () => DB::table('fiscal.documents')->where('id', $doc['id'])->update(['updated_at' => now()->subHour()]));
    $result = app(FiscalReconciler::class)->run(10);

    expect($result)->toBe(['resent' => 0, 'refreshed' => 1]);
    expect(TenantContext::asProvider(fn () => DB::table('fiscal.documents')->where('id', $doc['id'])->value('status')))->toBe('accepted');
});
