<?php

declare(strict_types=1);

it('el reporte de ventas calcula ingreso neto, medios de pago y resultado', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $company = $this->withToken($token)->getJson('/api/v1/companies')->json('data.0.id');

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 2]],
        'payments' => [['method' => 'yape', 'amount' => 236]],
    ])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/expenses', [
        'company_id' => $company, 'category' => 'Servicios', 'description' => 'Luz', 'method' => 'transfer', 'amount' => 20,
    ])->assertCreated();

    $report = $this->withToken($token)->getJson('/api/v1/reports/sales')->assertOk()->json('data');

    expect($report['total'])->toBe('236.00')->and($report['documents'])->toBe(1)
        ->and($report['revenue_net'])->toBe('200.00')->and($report['expenses'])->toBe('20.00')
        ->and($report['by_method'][0]['method'])->toBe('yape')
        ->and($report['estimated_result'])->toBe(bcsub($report['gross_margin'], '20.00', 2));
});

it('el reporte por producto lista ingreso y margen', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 118]],
    ])->assertCreated();

    $rows = $this->withToken($token)->getJson('/api/v1/reports/products')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['revenue'])->toBe('100.00')
        ->and($rows[0]['margin'])->toBe(bcsub($rows[0]['revenue'], $rows[0]['cost'], 2));
});
