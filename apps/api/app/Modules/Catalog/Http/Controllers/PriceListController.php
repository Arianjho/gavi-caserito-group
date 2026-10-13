<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\PriceResolver;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Catalog\Models\Party;
use App\Modules\Catalog\Models\PriceList;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Listas de precios con sus escalas y resolución de precio por cliente.
 */
class PriceListController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return PriceList::class;
    }

    /**
     * Regla de borrado: no se elimina una lista asignada a clientes.
     */
    protected function guard(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Clientes con esta lista' => ['table' => 'cat.parties', 'column' => 'price_list_id'],
        ]);
    }

    /**
     * Reglas de validación de una lista.
     *
     * @param Model|null $current Lista en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique(PriceList::class, 'name')->whereNot('status', 'deleted')->ignore($current?->getKey()),
            ],
        ];
    }

    /**
     * Precios de la lista con el nombre de cada variante.
     *
     * @param string $id Lista.
     */
    public function items(string $id): JsonResponse
    {
        $this->find($id);
        $rows = DB::table('cat.price_list_items as pi')
            ->join('cat.item_variants as v', 'v.id', '=', 'pi.variant_id')
            ->join('cat.items as i', 'i.id', '=', 'v.item_id')
            ->where('pi.price_list_id', $id)->orderBy('i.name')->orderBy('pi.min_qty')
            ->get(['pi.id', 'pi.variant_id', 'pi.min_qty', 'pi.price', 'i.name', 'v.name as variant_name']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Reemplaza los precios de la lista.
     *
     * @param Request $request Datos: `items` con variante, cantidad mínima y precio.
     * @param string $id Lista.
     */
    public function saveItems(Request $request, string $id): JsonResponse
    {
        $list = $this->find($id);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:2000'],
            'items.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')],
            'items.*.min_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($list, $data, $request): void {
            DB::table('cat.price_list_items')->where('price_list_id', $list->getKey())->delete();
            $seen = [];
            foreach ($data['items'] as $row) {
                $key = $row['variant_id'].'|'.(float) $row['min_qty'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                DB::table('cat.price_list_items')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $request->user()?->tenant_id,
                    'price_list_id' => $list->getKey(), 'variant_id' => $row['variant_id'],
                    'min_qty' => $row['min_qty'], 'price' => $row['price'],
                ]);
            }
        });

        return $this->items($id);
    }

    /**
     * Resuelve el precio de varias variantes para un cliente.
     *
     * @param Request $request Datos: `party_id` y `lines`.
     * @param PriceResolver $resolver Resolutor de precios.
     */
    public function resolve(Request $request, PriceResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'party_id' => ['nullable', 'uuid', Rule::exists(Party::class, 'id')],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);
        /** @var list<array{variant_id: string, qty: numeric-string}> $lines */
        $lines = array_map(fn (array $l): array => [
            'variant_id' => $l['variant_id'], 'qty' => (string) $l['qty'],
        ], $data['lines']);

        return response()->json(['data' => $resolver->resolve($data['party_id'] ?? null, $lines)]);
    }
}
