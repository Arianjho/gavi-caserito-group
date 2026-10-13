<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemBarcode;
use App\Modules\Catalog\Models\ItemVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Variantes de un producto (talla, color, presentación): alta individual,
 * generación de combinaciones, edición y activación.
 */
class VariantController extends Controller
{
    /**
     * Agrega una variante a un producto.
     *
     * @param Request $request Datos de la variante.
     * @param string $itemId Producto.
     */
    public function store(Request $request, string $itemId): JsonResponse
    {
        $item = Item::query()->where('status', '!=', 'deleted')->findOrFail($itemId);
        $data = $request->validate($this->rules(null) + ['name' => ['required', 'string', 'max:200']]);

        $variant = DB::transaction(function () use ($item, $data): ItemVariant {
            $variant = $this->createVariant($item, $data);
            $this->refreshFlag($item);

            return $variant;
        });

        return response()->json(['data' => $this->present($variant->load('barcodes'))], 201);
    }

    /**
     * Genera todas las combinaciones de los ejes indicados (talla x color).
     *
     * La variante predeterminada sin nombre se reutiliza para la primera
     * combinación; las que ya existen por nombre se omiten.
     *
     * @param Request $request Datos: `axes`, `sale_price` y `cost_reference`.
     * @param string $itemId Producto.
     */
    public function generate(Request $request, string $itemId): JsonResponse
    {
        $item = Item::query()->where('status', '!=', 'deleted')->findOrFail($itemId);
        $data = $request->validate([
            'axes' => ['required', 'array', 'min:1', 'max:3'],
            'axes.*.name' => ['required', 'string', 'max:40'],
            'axes.*.values' => ['required', 'array', 'min:1', 'max:30'],
            'axes.*.values.*' => ['required', 'string', 'max:40'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_reference' => ['nullable', 'numeric', 'min:0'],
        ]);

        $created = DB::transaction(function () use ($item, $data): int {
            $count = $this->generateCombinations($item, $data);
            $this->refreshFlag($item);

            return $count;
        });

        return response()->json([
            'data' => ['created' => $created, 'variants' => $this->variantsOf($item)],
        ], 201);
    }

    /**
     * Actualiza una variante.
     *
     * @param Request $request Datos a actualizar.
     * @param string $id Variante.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $variant = ItemVariant::query()->where('status', '!=', 'deleted')->findOrFail($id);
        $data = $request->validate($this->rules($variant));

        DB::transaction(function () use ($variant, $data): void {
            $variant->fill([
                'name' => $data['name'] ?? $variant->name,
                'sku' => $data['sku'] ?? null,
                'cost_reference' => $data['cost_reference'] ?? null,
                'sale_price' => $data['sale_price'] ?? null,
                'attributes' => $data['attributes'] ?? $variant->attributes,
            ])->save();
            $this->syncBarcode($variant, $data['barcode'] ?? null);
        });

        return response()->json(['data' => $this->present($variant->refresh()->load('barcodes'))]);
    }

    /**
     * Activa o desactiva una variante; no se puede dejar el producto sin variantes activas.
     *
     * @param Request $request Petición con `active`.
     * @param string $id Variante.
     */
    public function setActive(Request $request, string $id): JsonResponse
    {
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $variant = ItemVariant::query()->where('status', '!=', 'deleted')->findOrFail($id);

        if (! $active) {
            $others = ItemVariant::query()->where('item_id', $variant->item_id)
                ->where('status', 'active')->whereKeyNot($variant->id)->count();
            if ($others === 0) {
                return response()->json([
                    'code' => 'last_variant',
                    'message' => 'El producto debe conservar al menos una variante activa.',
                ], 422);
            }
        }

        $variant->forceFill(['status' => $active ? 'active' : 'inactive'])->save();

        return response()->json(['data' => $this->present($variant->load('barcodes'))]);
    }

    /**
     * Reglas de validación de una variante.
     *
     * @param ItemVariant|null $current Variante en edición.
     * @return array<string, mixed>
     */
    private function rules(?ItemVariant $current): array
    {
        $barcode = $current === null ? null : ItemBarcode::query()->where('variant_id', $current->id)->value('id');

        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'sku' => [
                'nullable', 'string', 'max:60',
                Rule::unique(ItemVariant::class, 'sku')->whereNot('status', 'deleted')->ignore($current?->getKey()),
            ],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique(ItemBarcode::class, 'code')->ignore($barcode === null ? null : (string) $barcode),
            ],
            'cost_reference' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['string', 'max:40'],
        ];
    }

    /**
     * Crea las combinaciones faltantes y devuelve cuántas se agregaron.
     *
     * @param Item $item Producto.
     * @param array<string, mixed> $data Ejes y precios base.
     */
    private function generateCombinations(Item $item, array $data): int
    {
        $existing = ItemVariant::query()->where('item_id', $item->id)->where('status', '!=', 'deleted')->get();
        $names = $existing->pluck('name')->filter()->map(fn (string $n): string => mb_strtolower($n))->all();
        $default = $existing->first(fn (ItemVariant $v): bool => $v->is_default && $v->name === null);
        $created = 0;

        foreach ($this->combinations($data['axes']) as $attributes) {
            $name = implode(' / ', $attributes);
            if (in_array(mb_strtolower($name), $names, true)) {
                continue;
            }
            $names[] = mb_strtolower($name);
            $created++;

            if ($default !== null) {
                $default->fill(['name' => $name, 'attributes' => $attributes])->save();
                $default = null;

                continue;
            }
            $this->createVariant($item, [
                'name' => $name, 'attributes' => $attributes,
                'sale_price' => $data['sale_price'] ?? $this->baseValue($item, 'sale_price'),
                'cost_reference' => $data['cost_reference'] ?? $this->baseValue($item, 'cost_reference'),
            ]);
        }

        return $created;
    }

    /**
     * Producto cartesiano de los valores de cada eje.
     *
     * @param list<array{name: string, values: list<string>}> $axes Ejes.
     * @return list<array<string, string>>
     */
    private function combinations(array $axes): array
    {
        $result = [[]];
        foreach ($axes as $axis) {
            $next = [];
            foreach ($result as $partial) {
                foreach (array_unique($axis['values']) as $value) {
                    $next[] = $partial + [$axis['name'] => $value];
                }
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * Valor de precio o costo de la variante predeterminada, para heredarlo.
     *
     * @param Item $item Producto.
     * @param string $column `sale_price` o `cost_reference`.
     */
    private function baseValue(Item $item, string $column): ?string
    {
        $value = ItemVariant::query()->where('item_id', $item->id)->where('is_default', true)->value($column);

        return $value === null ? null : (string) $value;
    }

    /**
     * Inserta una variante con su código de barras opcional.
     *
     * @param Item $item Producto.
     * @param array<string, mixed> $data Datos validados.
     */
    private function createVariant(Item $item, array $data): ItemVariant
    {
        $variant = ItemVariant::create([
            'tenant_id' => $item->tenant_id, 'item_id' => $item->id, 'is_default' => false,
            'name' => $data['name'], 'sku' => $data['sku'] ?? null,
            'attributes' => $data['attributes'] ?? null,
            'cost_reference' => $data['cost_reference'] ?? null, 'sale_price' => $data['sale_price'] ?? null,
        ]);
        $this->syncBarcode($variant, $data['barcode'] ?? null);

        return $variant;
    }

    /**
     * Marca el producto como "con variantes" si tiene más de una activa.
     *
     * @param Item $item Producto.
     */
    private function refreshFlag(Item $item): void
    {
        $count = ItemVariant::query()->where('item_id', $item->id)->where('status', '!=', 'deleted')->count();
        $item->forceFill(['has_variants' => $count > 1])->save();
    }

    /**
     * Reemplaza el código de barras de la variante.
     *
     * @param ItemVariant $variant Variante.
     * @param string|null $code Código nuevo; nulo lo elimina.
     */
    private function syncBarcode(ItemVariant $variant, ?string $code): void
    {
        $existing = ItemBarcode::query()->where('variant_id', $variant->id)->first();
        if ($code === null || $code === '') {
            $existing?->delete();

            return;
        }
        $kind = preg_match('/^\d{13}$/', $code) === 1 ? 'ean13' : 'code128';
        if ($existing !== null) {
            $existing->fill(['code' => $code, 'kind' => $kind])->save();

            return;
        }
        ItemBarcode::create(['tenant_id' => $variant->tenant_id, 'variant_id' => $variant->id, 'code' => $code, 'kind' => $kind]);
    }

    /**
     * Variantes del producto ya presentadas.
     *
     * @param Item $item Producto.
     * @return array<int, array<string, mixed>>
     */
    private function variantsOf(Item $item): array
    {
        return ItemVariant::query()->where('item_id', $item->id)->where('status', '!=', 'deleted')
            ->with('barcodes')->orderBy('name')->get()
            ->map(fn (ItemVariant $v): array => $this->present($v))->values()->all();
    }

    /**
     * Datos públicos de una variante.
     *
     * @param ItemVariant $variant Variante con códigos cargados.
     * @return array<string, mixed>
     */
    private function present(ItemVariant $variant): array
    {
        return [
            'id' => $variant->id, 'item_id' => $variant->item_id, 'sku' => $variant->sku, 'name' => $variant->name,
            'attributes' => $variant->attributes, 'is_default' => $variant->is_default, 'status' => $variant->status,
            'cost_reference' => $variant->cost_reference, 'sale_price' => $variant->sale_price,
            'barcodes' => $variant->barcodes->pluck('code')->values(),
        ];
    }
}
