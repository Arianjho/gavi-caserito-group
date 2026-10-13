<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemBarcode;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Inventory\Application\StockService;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Importación masiva de productos desde filas ya leídas del Excel.
 *
 * Cada fila se procesa de forma independiente: las válidas se crean y las
 * inválidas se reportan con su motivo, sin detener el resto. Con `dryRun`
 * solo valida. Categorías y marcas se resuelven por nombre y se crean si
 * faltan; el stock inicial entra como movimiento de apertura.
 */
final class ItemImportService
{
    /**
     * Crea el servicio.
     *
     * @param StockService $stock Servicio de stock.
     */
    public function __construct(private readonly StockService $stock) {}

    /**
     * Importa o valida las filas.
     *
     * @param string $tenantId Cuenta.
     * @param list<array<string, mixed>> $rows Filas con `name`, `sku`, `barcode`, `category`, `brand`, `cost`, `price` y `stock`.
     * @param string|null $warehouseId Almacén que recibe el stock inicial.
     * @param bool $dryRun Solo validar, sin escribir.
     * @return array{created: int, failed: int, rows: list<array{row: int, name: string, status: string, errors: list<string>}>}
     */
    public function import(string $tenantId, array $rows, ?string $warehouseId, bool $dryRun): array
    {
        $seenSku = [];
        $seenBarcode = [];
        $results = [];
        $created = 0;

        foreach ($rows as $index => $row) {
            $errors = $this->validateRow($row, $seenSku, $seenBarcode, $warehouseId);
            $status = $errors === [] ? ($dryRun ? 'valid' : 'created') : 'error';

            if ($errors === [] && ! $dryRun) {
                DB::transaction(fn () => $this->createItem($tenantId, $row, $warehouseId));
                $created++;
            }
            $results[] = [
                'row' => $index + 1, 'name' => (string) ($row['name'] ?? ''),
                'status' => $status, 'errors' => $errors,
            ];
        }

        return [
            'created' => $created,
            'failed' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'error')),
            'rows' => $results,
        ];
    }

    /**
     * Valida una fila y registra SKU y códigos vistos para detectar repetidos.
     *
     * @param array<string, mixed> $row Fila.
     * @param array<string, true> $seenSku SKU ya vistos en el archivo.
     * @param array<string, true> $seenBarcode Códigos de barras ya vistos.
     * @param string|null $warehouseId Almacén del stock inicial.
     * @return list<string> Mensajes de error (vacío si es válida).
     */
    private function validateRow(array $row, array &$seenSku, array &$seenBarcode, ?string $warehouseId): array
    {
        $validator = Validator::make($row, [
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:60'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'name' => 'nombre', 'sku' => 'SKU', 'barcode' => 'código de barras', 'category' => 'categoría',
            'brand' => 'marca', 'cost' => 'costo', 'price' => 'precio', 'stock' => 'stock',
        ]);
        $errors = $validator->errors()->all();

        $sku = trim((string) ($row['sku'] ?? ''));
        $barcode = trim((string) ($row['barcode'] ?? ''));
        $errors = [...$errors, ...$this->duplicates($sku, $barcode, $seenSku, $seenBarcode)];

        if ((float) ($row['stock'] ?? 0) > 0 && $warehouseId === null) {
            $errors[] = 'Indica el almacén para cargar stock inicial.';
        }

        return array_values($errors);
    }

    /**
     * Detecta SKU y códigos de barras repetidos en el archivo o ya existentes.
     *
     * @param string $sku SKU de la fila.
     * @param string $barcode Código de barras de la fila.
     * @param array<string, true> $seenSku SKU vistos.
     * @param array<string, true> $seenBarcode Códigos vistos.
     * @return list<string>
     */
    private function duplicates(string $sku, string $barcode, array &$seenSku, array &$seenBarcode): array
    {
        $errors = [];
        if ($sku !== '') {
            $key = mb_strtolower($sku);
            $taken = isset($seenSku[$key])
                || ItemVariant::query()->where('status', '!=', 'deleted')->whereRaw('lower(sku) = ?', [$key])->exists();
            $taken ? $errors[] = "El SKU {$sku} ya existe." : $seenSku[$key] = true;
        }
        if ($barcode !== '') {
            $taken = isset($seenBarcode[$barcode]) || ItemBarcode::query()->where('code', $barcode)->exists();
            $taken ? $errors[] = "El código de barras {$barcode} ya existe." : $seenBarcode[$barcode] = true;
        }

        return $errors;
    }

    /**
     * Crea el ítem, su variante predeterminada, código de barras y stock inicial.
     *
     * @param string $tenantId Cuenta.
     * @param array<string, mixed> $row Fila ya validada.
     * @param string|null $warehouseId Almacén del stock inicial.
     */
    private function createItem(string $tenantId, array $row, ?string $warehouseId): void
    {
        $item = Item::create([
            'tenant_id' => $tenantId, 'kind' => 'good', 'name' => trim((string) $row['name']),
            'category_id' => $this->resolve(Category::class, $tenantId, $row['category'] ?? null),
            'brand_id' => $this->resolve(Brand::class, $tenantId, $row['brand'] ?? null),
            'base_uom' => 'NIU', 'tracks_stock' => true,
        ]);
        $sku = trim((string) ($row['sku'] ?? ''));
        $variant = ItemVariant::create([
            'tenant_id' => $tenantId, 'item_id' => $item->id, 'is_default' => true,
            'sku' => $sku === '' ? null : $sku,
            'cost_reference' => $this->number($row['cost'] ?? null),
            'sale_price' => $this->number($row['price'] ?? null),
        ]);

        $barcode = trim((string) ($row['barcode'] ?? ''));
        if ($barcode !== '') {
            ItemBarcode::create([
                'tenant_id' => $tenantId, 'variant_id' => $variant->id, 'code' => $barcode,
                'kind' => preg_match('/^\d{13}$/', $barcode) === 1 ? 'ean13' : 'code128',
            ]);
        }

        $stock = $this->number($row['stock'] ?? null);
        if ($stock !== null && $warehouseId !== null && bccomp($stock, '0', 3) > 0) {
            /** @var numeric-string $stock */
            $this->stock->post($warehouseId, $variant->id, 'opening', $stock, $this->number($row['cost'] ?? null), [
                'source_type' => 'import', 'source_id' => (string) Str::uuid(), 'reason' => 'Carga inicial por importación',
            ]);
        }
    }

    /**
     * Busca por nombre (sin distinguir mayúsculas) o crea la categoría o marca.
     *
     * @param class-string<Category|Brand> $model Modelo.
     * @param string $tenantId Cuenta.
     * @param mixed $name Nombre indicado en la fila.
     */
    private function resolve(string $model, string $tenantId, mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $id = $model::query()->where('status', '!=', 'deleted')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id');
        if (is_string($id)) {
            return $id;
        }

        return $model::create(['tenant_id' => $tenantId, 'name' => $name])->id;
    }

    /**
     * Normaliza un valor numérico opcional a texto decimal.
     *
     * @param mixed $value Valor leído.
     * @return numeric-string|null
     */
    private function number(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return Decimal::of($value);
    }
}
