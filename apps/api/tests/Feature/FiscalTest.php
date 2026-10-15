<?php

declare(strict_types=1);

use App\Modules\Fiscal\Application\FiscalReconciler;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Client\Factory;
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

/**
 * Reemplaza las respuestas simuladas de eFact (la primera que coincide gana).
 *
 * @param array<string, mixed> $body Cuerpo de la respuesta.
 * @param int $status Código HTTP.
 */
function efactResponds(array $body, int $status = 201): void
{
    Http::swap(new Factory);
    Http::fake(['efact.test/*' => Http::response($body, $status)]);
}

/**
 * Emite una boleta de dos unidades y la deja aceptada.
 *
 * @param string $token Token del propietario.
 * @param int $efactId Identificador que devuelve eFact.
 * @return array{doc: string, sale: string, line: string, company: string, efact: int}
 */
function acceptedBoleta(string $token, int $efactId = 345): array
{
    $f = fiscalFixture($token);
    $sale = test()->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 2]],
        'payments' => [['method' => 'yape', 'amount' => 236]],
    ])->assertCreated()->json('data.id');
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => $efactId, 'serie' => 'B001', 'correlativo' => '00000345', 'sunat' => ['estado' => 'aceptado', 'codigo' => '0']],
    ], 201);
    $doc = test()->withToken($token)->postJson("/api/v1/sales/{$sale}/emit", ['cpe_type' => '03'])->assertStatus(202)->json('data.id');
    $line = test()->withToken($token)->getJson("/api/v1/sales/{$sale}")->json('data.lines.0.id');

    return ['doc' => $doc, 'sale' => $sale, 'line' => $line, 'company' => $f['company'], 'efact' => $efactId];
}

it('emite una nota de credito parcial, controla el saldo y rechaza lo que sobra', function (): void {
    $token = ownerToken();
    $b = acceptedBoleta($token);
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => 10, 'serie' => 'BC01', 'correlativo' => '00000010', 'sunat' => ['estado' => 'aceptado', 'codigo' => '0']],
    ], 201);

    $note = $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '06', 'reason_text' => 'Devolución de una unidad', 'lines' => [['line_id' => $b['line'], 'qty' => 1]],
    ])->assertStatus(202)->json('data');

    expect($note['status'])->toBe('accepted')->and($note['series'])->toBe('BC01')->and($note['total'])->toBe('118.00');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/notas-credito')
        && $r['cod_motivo'] === '06' && $r['doc_afectado_tipo'] === '03' && $r['doc_afectado_serie'] === 'B001'
        && $r['doc_afectado_correlativo'] === '00000345' && $r['items'][0]['cantidad'] === 1.0);

    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '06', 'reason_text' => 'Otra', 'lines' => [['line_id' => $b['line'], 'qty' => 2]],
    ])->assertStatus(422)->assertJsonPath('code', 'qty_exceeds_sold');
    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '01', 'reason_text' => 'Anulación',
    ])->assertStatus(409)->assertJsonPath('code', 'already_credited');
    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '06', 'reason_text' => 'Resto',
    ])->assertStatus(202);
    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '06', 'reason_text' => 'Sobra',
    ])->assertStatus(409)->assertJsonPath('code', 'nothing_to_credit');
});

it('una nota de credito total puede reingresar el stock y devolver el dinero', function (): void {
    $token = ownerToken();
    $b = acceptedBoleta($token);
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => 11, 'serie' => 'BC01', 'correlativo' => '00000011', 'sunat' => ['estado' => 'aceptado']],
    ], 201);

    $note = $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '01', 'reason_text' => 'Anulación de la operación', 'refund_method' => 'yape',
    ])->assertStatus(202)->json('data');

    expect($note['total'])->toBe('236.00')->and($note['sale_return_id'])->not->toBeNull();
    $returns = TenantContext::asProvider(fn () => DB::table('sales.sale_returns')->where('sale_id', $b['sale'])->get());
    expect($returns)->toHaveCount(1)->and($returns[0]->total)->toBe('236.00');
});

it('la nota por monto usa un solo item y no supera el comprobante', function (): void {
    $token = ownerToken();
    $b = acceptedBoleta($token);
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => 12, 'serie' => 'BC01', 'correlativo' => '00000012', 'sunat' => ['estado' => 'enviado']],
    ], 201);

    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '04', 'reason_text' => 'Descuento comercial posterior', 'amount' => 20,
    ])->assertStatus(202)->assertJsonPath('data.total', '20.00')->assertJsonPath('data.status', 'pending');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/notas-credito')
        && $r['items'][0]['unidad'] === 'ZZ' && $r['items'][0]['precio_unitario'] === 20.0);

    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '04', 'reason_text' => 'Demasiado', 'amount' => 300,
    ])->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_document');
});

it('solo un comprobante aceptado admite nota de credito', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => 5, 'serie' => 'B001', 'correlativo' => '00000005', 'sunat' => ['estado' => 'enviado']],
    ], 201);
    $doc = $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '03'])->json('data.id');

    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$doc}/credit-notes", [
        'reason_code' => '01', 'reason_text' => 'Anulación',
    ])->assertStatus(409)->assertJsonPath('code', 'document_not_accepted');
});

it('la baja de una boleta usa un resumen de anulacion y anula la venta al aceptarse', function (): void {
    $token = ownerToken();
    $b = acceptedBoleta($token);
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id_resumen' => 6, 'identifier' => 'RC-1', 'estado_sunat' => 'aceptado', 'codigo_sunat' => '0'],
    ], 201);

    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/void", ['reason' => 'Error en la venta'])
        ->assertStatus(202)->assertJsonPath('data.status', 'voided')->assertJsonPath('data.void_status', 'accepted');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/resumenes')
        && $r['anular'][0]['id'] === 345 && $r['anular'][0]['tipo_documento'] === '03');
    $this->withToken($token)->getJson("/api/v1/sales/{$b['sale']}")->assertJsonPath('data.status', 'voided');
});

it('la baja no procede si el comprobante tiene notas de credito', function (): void {
    $token = ownerToken();
    $b = acceptedBoleta($token);
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => 13, 'serie' => 'BC01', 'correlativo' => '00000013', 'sunat' => ['estado' => 'aceptado']],
    ], 201);
    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/credit-notes", [
        'reason_code' => '06', 'reason_text' => 'Una unidad', 'lines' => [['line_id' => $b['line'], 'qty' => 1]],
    ])->assertStatus(202);

    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/void", ['reason' => 'Error'])
        ->assertStatus(409)->assertJsonPath('code', 'has_credit_note');

});

it('no admite baja pasados 7 dias de la emision', function (): void {
    $token = ownerToken();
    $b = acceptedBoleta($token);
    TenantContext::asProvider(fn () => DB::table('sales.sales_documents')->where('id', $b['sale'])
        ->update(['issued_at' => now()->subDays(9)]));

    $this->withToken($token)->postJson("/api/v1/fiscal/documents/{$b['doc']}/void", ['reason' => 'Tarde'])
        ->assertStatus(422)->assertJsonPath('code', 'void_window_expired');
});

it('emite una guia de remision de transporte privado desde una venta', function (): void {
    $token = ownerToken();
    $b = acceptedBoleta($token);
    $payload = [
        'sales_document_id' => $b['sale'], 'transfer_reason' => '01', 'transport_mode' => '02', 'transfer_date' => now()->toDateString(),
        'weight' => 12.5, 'packages' => 2, 'arrival_ubigeo' => '150135', 'arrival_address' => 'AV. LIMA 123',
        'recipient' => ['doc_type' => 'DNI', 'doc_number' => '12345678', 'name' => 'JUAN PEREZ'],
        'vehicle_plate' => 'abc-123',
        'driver' => ['doc_number' => '87654321', 'first_name' => 'LUIS', 'last_name' => 'GOMEZ', 'license' => 'Q12345678'],
    ];

    TenantContext::asProvider(fn () => DB::table('core.branches')->update(['ubigeo' => null]));
    $this->withToken($token)->postJson('/api/v1/fiscal/dispatch-guides', $payload)
        ->assertStatus(422)->assertJsonPath('code', 'branch_ubigeo_required');

    TenantContext::asProvider(fn () => DB::table('core.branches')->update(['ubigeo' => '150101', 'address' => 'AV. ALMACEN 456']));
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => 88, 'serie' => 'T001', 'correlativo' => '00000088', 'sunat_status' => 'aceptado', 'sunat_code' => '0'],
    ], 201);

    $guide = $this->withToken($token)->postJson('/api/v1/fiscal/dispatch-guides', $payload)->assertStatus(202)->json('data');

    expect($guide['status'])->toBe('accepted')->and($guide['number'])->toBe('00000088')->and($guide['series'])->toBe('T001');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/guias-remision')
        && $r['mod_traslado'] === '02' && $r['vehiculo']['placa'] === 'ABC-123'
        && $r['conductor']['num_doc'] === '87654321' && $r['partida_ubigeo'] === '150101'
        && $r['destinatario']['tipo_doc'] === '1' && (float) $r['items'][0]['cantidad'] === 2.0);

    $this->withToken($token)->postJson('/api/v1/fiscal/dispatch-guides', array_diff_key($payload, ['driver' => 1]))
        ->assertStatus(422)->assertJsonPath('code', 'driver_required');
});

it('el cupo mensual bloquea nuevas emisiones sin perder la venta', function (): void {
    $token = ownerToken();
    $f = fiscalFixture($token);
    $second = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'yape', 'amount' => 118]],
    ])->assertCreated()->json('data.id');
    TenantContext::asProvider(fn () => DB::table('core.tenants')->update(['max_documents_month' => 2]));
    efactResponds([
        'estado' => 'exito',
        'datos' => ['id' => 1, 'serie' => 'B001', 'correlativo' => '00000001', 'sunat' => ['estado' => 'aceptado']],
    ]);

    $this->withToken($token)->postJson("/api/v1/sales/{$f['sale']}/emit", ['cpe_type' => '03'])->assertStatus(202);
    $plan = $this->withToken($token)->getJson('/api/v1/plan')->json('data.limits.documents_month');
    expect($plan['used'])->toBe(1)->and($plan['level'])->toBe('ok');

    $this->withToken($token)->postJson("/api/v1/sales/{$second}/emit", ['cpe_type' => '03'])->assertStatus(202);
    $this->withToken($token)->getJson('/api/v1/plan')->assertJsonPath('data.limits.documents_month.level', 'reached');

    $third = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'yape', 'amount' => 118]],
    ])->assertCreated()->json('data.id');
    $this->withToken($token)->postJson("/api/v1/sales/{$third}/emit", ['cpe_type' => '03'])
        ->assertStatus(403)->assertJsonPath('code', 'document_quota_reached');
});
