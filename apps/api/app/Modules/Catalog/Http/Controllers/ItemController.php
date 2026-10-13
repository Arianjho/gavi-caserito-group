<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogDeletionGuards;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemBarcode;
use App\Modules\Catalog\Models\ItemVariant;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestión de productos y servicios con su variante predeterminada.
 *
 * Todo ítem se crea con una variante predeterminada que lleva el SKU, el
 * costo de referencia y el código de barras; así pedidos, ventas y stock
 * trabajan siempre con variantes.
 */
class ItemController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Item::class;
    }

    /**
     * Regla de borrado de ítems.
     */
    protected function guard(): DeletionGuard
    {
        return CatalogDeletionGuards::forItem();
    }

    /**
     * Reglas de validación de un ítem y de su variante predeterminada.
     *
     * @param Model|null $current Ítem en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        $variant = $current instanceof Item ? $this->defaultVariant($current) : null;

        return [
            'kind' => ['sometimes', Rule::in(['good', 'service'])],
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'nullable', 'string', 'max:40',
                Rule::unique(Item::class, 'code')->whereNot('status', 'deleted')->ignore($current?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'uuid', Rule::exists(Category::class, 'id')],
            'brand_id' => ['nullable', 'uuid', Rule::exists(Brand::class, 'id')],
            'base_uom' => ['sometimes', 'string', 'size:3'],
            'tracks_stock' => ['sometimes', 'boolean'],
            'sku' => [
                'nullable', 'string', 'max:60',
                Rule::unique(ItemVariant::class, 'sku')->whereNot('status', 'deleted')->ignore($variant?->getKey()),
            ],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique(ItemBarcode::class, 'code')->ignore($variant === null ? null : $this->barcodeId($variant)),
            ],
            'cost_reference' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Lista ítems con sus variantes; admite búsqueda por nombre, código, SKU o barras.
     *
     * @param Request $request Admite `q` (texto) y `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Item::query()->where('status', '!=', 'deleted')->with('variants.barcodes')->orderBy('name');

        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->query('status'));
        }

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(function ($q) use ($like, $term): void {
                $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(code) like ?', [$like])
                    ->orWhereHas('variants', fn ($v) => $v->whereRaw('lower(sku) like ?', [$like])
                        ->orWhereHas('barcodes', fn ($b) => $b->where('code', $term)));
            });
        }

        $page = $query->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($page->items())->map(fn (Item $item): array => $this->presentItem($item))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * Busca la variante a partir de un código de barras, SKU o código interno (POS).
     *
     * @param Request $request Requiere `code`.
     */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['data' => null]);
        }

        $variant = ItemVariant::query()
            ->where('status', 'active')
            ->where(function ($q) use ($code): void {
                $q->whereRaw('lower(sku) = ?', [mb_strtolower($code)])
                    ->orWhereHas('barcodes', fn ($b) => $b->where('code', $code));
            })
            ->first();

        if ($variant === null) {
            return response()->json(['data' => null], 404);
        }

        $item = Item::query()->with('variants.barcodes')->findOrFail($variant->item_id);

        $data = $this->presentItem($item);
        $data['variants'] = array_values(array_filter(
            $data['variants']->all(),
            fn (array $v): bool => $v['id'] === $variant->id,
        ));
        if ($item->has_variants && $variant->name !== null) {
            $data['name'] = $item->name.' '.$variant->name;
        }

        return response()->json(['data' => $data + ['variant_id' => $variant->id]]);
    }

    /**
     * Crea un ítem con su variante predeterminada y, si viene, su código de barras.
     *
     * @param Request $request Datos del ítem.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null));
        $tenantId = (string) $request->user()?->tenant_id;

        $item = DB::transaction(function () use ($data, $tenantId): Item {
            $kind = $data['kind'] ?? 'good';
            $item = Item::create([
                'tenant_id' => $tenantId,
                'kind' => $kind,
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'base_uom' => $data['base_uom'] ?? ($kind === 'service' ? 'ZZ' : 'NIU'),
                'tracks_stock' => $kind === 'service' ? false : (bool) ($data['tracks_stock'] ?? true),
            ]);

            $variant = ItemVariant::create([
                'tenant_id' => $tenantId,
                'item_id' => $item->id,
                'sku' => $data['sku'] ?? null,
                'is_default' => true,
                'cost_reference' => $data['cost_reference'] ?? null,
                'sale_price' => $data['sale_price'] ?? null,
            ]);

            if (! empty($data['barcode'])) {
                ItemBarcode::create([
                    'tenant_id' => $tenantId, 'variant_id' => $variant->id,
                    'code' => $data['barcode'], 'kind' => $this->barcodeKind($data['barcode']),
                ]);
            }

            return $item;
        });

        return response()->json(['data' => $this->presentItem($item->load('variants.barcodes'))], 201);
    }

    /**
     * Actualiza el ítem y los datos de su variante predeterminada.
     *
     * @param Request $request Datos a actualizar.
     * @param string $id Identificador del ítem.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var Item $item */
        $item = $this->find($id);
        $data = $request->validate($this->rules($item));

        DB::transaction(function () use ($item, $data): void {
            $kind = $data['kind'] ?? $item->kind;
            $item->fill([
                'kind' => $kind,
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'base_uom' => $data['base_uom'] ?? $item->base_uom,
                'tracks_stock' => $kind === 'service' ? false : (bool) ($data['tracks_stock'] ?? $item->tracks_stock),
            ])->save();

            $variant = $this->defaultVariant($item);
            if ($variant !== null) {
                $variant->fill([
                    'sku' => $data['sku'] ?? null,
                    'cost_reference' => $data['cost_reference'] ?? null,
                    'sale_price' => $data['sale_price'] ?? null,
                ])->save();
                $this->syncBarcode($variant, $data['barcode'] ?? null);
            }
        });

        return response()->json(['data' => $this->presentItem($item->refresh()->load('variants.barcodes'))]);
    }

    /**
     * Datos de un ítem con sus variantes para la respuesta.
     *
     * @param Model $model Ítem a presentar.
     * @return array<string, mixed>
     */
    protected function present(Model $model): array
    {
        return $this->presentItem($model instanceof Item ? $model->loadMissing('variants.barcodes') : $model);
    }

    /**
     * Estructura pública de un ítem.
     *
     * @param Model $model Ítem con variantes cargadas.
     * @return array<string, mixed>
     */
    private function presentItem(Model $model): array
    {
        /** @var Item $item */
        $item = $model;

        return [
            'id' => $item->id,
            'status' => $item->status,
            'kind' => $item->kind,
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'category_id' => $item->category_id,
            'brand_id' => $item->brand_id,
            'base_uom' => $item->base_uom,
            'tracks_stock' => $item->tracks_stock,
            'has_variants' => $item->has_variants,
            'variants' => $item->variants->map(fn (ItemVariant $variant): array => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'attributes' => $variant->attributes,
                'status' => $variant->status,
                'is_default' => $variant->is_default,
                'cost_reference' => $variant->cost_reference,
                'sale_price' => $variant->sale_price,
                'barcodes' => $variant->barcodes->pluck('code')->values(),
            ])->values(),
        ];
    }

    /**
     * Variante predeterminada del ítem.
     *
     * @param Item $item Ítem.
     */
    private function defaultVariant(Item $item): ?ItemVariant
    {
        return ItemVariant::query()->where('item_id', $item->id)->where('is_default', true)
            ->where('status', '!=', 'deleted')->first();
    }

    /**
     * Identificador del primer código de barras de una variante.
     *
     * @param ItemVariant $variant Variante.
     */
    private function barcodeId(ItemVariant $variant): ?string
    {
        $id = ItemBarcode::query()->where('variant_id', $variant->id)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Reemplaza el código de barras principal de la variante.
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
        if ($existing !== null) {
            $existing->fill(['code' => $code, 'kind' => $this->barcodeKind($code)])->save();

            return;
        }
        ItemBarcode::create([
            'tenant_id' => $variant->tenant_id, 'variant_id' => $variant->id,
            'code' => $code, 'kind' => $this->barcodeKind($code),
        ]);
    }

    /**
     * Deduce el tipo de código de barras por su forma.
     *
     * @param string $code Código leído o digitado.
     */
    private function barcodeKind(string $code): string
    {
        return preg_match('/^\d{13}$/', $code) === 1 ? 'ean13' : 'code128';
    }
}
