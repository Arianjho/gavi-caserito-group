<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Party;
use App\Modules\Catalog\Models\PriceList;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;
use App\Shared\Rules\PeruvianRuc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestión de clientes y proveedores.
 *
 * La misma tabla sirve a ambos; `scope` en el listado filtra por rol.
 */
class PartyController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Party::class;
    }

    /**
     * Regla de borrado: no se elimina un tercero con documentos de venta.
     */
    protected function guard(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Ventas registradas' => ['table' => 'sales.sales_documents', 'column' => 'party_id'],
        ]);
    }

    /**
     * Reglas de validación de un tercero.
     *
     * @param Model|null $current Tercero en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        $type = request()->input('doc_type', 'NONE');

        return [
            'doc_type' => ['required', Rule::in(['DNI', 'RUC', 'CE', 'PAS', 'NONE'])],
            'doc_number' => array_merge(
                ['nullable', 'string', 'max:20', Rule::requiredIf($type !== 'NONE')],
                match ($type) {
                    'DNI' => ['digits:8'],
                    'RUC' => [new PeruvianRuc],
                    default => [],
                },
                [Rule::unique(Party::class, 'doc_number')
                    ->where('doc_type', $type)->whereNot('status', 'deleted')->ignore($current?->getKey())],
            ),
            'name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['nullable', 'digits:6'],
            'is_customer' => ['sometimes', 'boolean'],
            'is_supplier' => ['sometimes', 'boolean'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'price_list_id' => ['nullable', 'uuid', Rule::exists(PriceList::class, 'id')->where('status', 'active')],
        ];
    }

    /**
     * Lista terceros con búsqueda por nombre, documento o teléfono.
     *
     * @param Request $request Admite `q`, `status` y `scope` (`customer` o `supplier`).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Party::query()->where('status', '!=', 'deleted')->orderBy('name');

        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->query('status'));
        }
        if ($request->query('scope') === 'customer') {
            $query->where('is_customer', true);
        }
        if ($request->query('scope') === 'supplier') {
            $query->where('is_supplier', true);
        }
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(doc_number) like ?', [$like])
                ->orWhereRaw('lower(phone) like ?', [$like]));
        }

        return response()->json([
            'data' => $query->limit(200)->get()->map(fn (Model $party): array => $this->present($party))->values(),
        ]);
    }
}
