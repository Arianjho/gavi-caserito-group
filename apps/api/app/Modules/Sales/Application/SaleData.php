<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * Datos de entrada para registrar una venta.
 */
final readonly class SaleData
{
    /**
     * Crea los datos de la venta.
     *
     * @param string $tenantId Cuenta del usuario.
     * @param string $warehouseId Almacén del que sale el stock.
     * @param list<array{variant_id: string, qty: numeric-string, unit_price?: numeric-string|null, discount_pct?: numeric-string|null}> $lines Líneas de la venta.
     * @param list<array{method: string, amount: numeric-string, reference?: string|null}> $payments Cobros recibidos ahora.
     * @param string|null $notes Observaciones.
     * @param string|null $partyId Cliente (obligatorio a crédito).
     * @param string|null $creditDueDate Vencimiento (Y-m-d); si viene, la venta es a crédito.
     * @param string|null $orderId Pedido del que proviene la venta.
     * @param numeric-string $advance Anticipo del pedido aplicado a esta venta.
     */
    public function __construct(
        public string $tenantId,
        public string $warehouseId,
        public array $lines,
        public array $payments,
        public ?string $notes = null,
        public ?string $partyId = null,
        public ?string $creditDueDate = null,
        public ?string $orderId = null,
        public string $advance = '0.00',
    ) {}
}
