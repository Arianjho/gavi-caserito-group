<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\OrganizationDeletionGuards;
use App\Modules\Platform\Models\Branch;
use App\Modules\Platform\Models\Warehouse;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Gestión de almacenes de las sucursales.
 */
class WarehouseController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Warehouse::class;
    }

    /**
     * Regla de borrado de almacenes.
     */
    protected function guard(): DeletionGuard
    {
        return OrganizationDeletionGuards::forWarehouse();
    }

    /**
     * Reglas de validación de un almacén.
     *
     * La sucursal solo se define al crear; la empresa se deduce de ella.
     *
     * @param Model|null $current Almacén en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['sales', 'storage', 'transit'])],
            'allows_negative' => ['nullable', 'boolean'],
        ];

        if ($current === null) {
            $rules['branch_id'] = ['required', 'uuid', Rule::exists(Branch::class, 'id')->where('status', 'active')];
        }

        return $rules;
    }

    /**
     * Deduce la empresa a partir de la sucursal elegida.
     *
     * @param array<string, mixed> $data Datos validados.
     * @return array<string, mixed>
     */
    protected function extraOnCreate(array $data): array
    {
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail($data['branch_id']);

        return ['company_id' => $branch->company_id];
    }

    /**
     * Presenta el almacén incluyendo sucursal y empresa.
     *
     * @param Model $model Almacén.
     * @return array<string, mixed>
     */
    protected function present(Model $model): array
    {
        return array_merge(parent::present($model), $model->only(['company_id', 'branch_id']));
    }
}
