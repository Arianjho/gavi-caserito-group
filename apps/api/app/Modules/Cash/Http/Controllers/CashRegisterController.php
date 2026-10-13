<?php

declare(strict_types=1);

namespace App\Modules\Cash\Http\Controllers;

use App\Modules\Cash\Models\CashRegister;
use App\Modules\Platform\Models\Branch;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Gestión de las cajas de cobro por sucursal.
 */
class CashRegisterController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return CashRegister::class;
    }

    /**
     * Regla de borrado: una caja con turnos no se elimina, se desactiva.
     */
    protected function guard(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Turnos de la caja' => ['table' => 'cash.cash_sessions', 'column' => 'cash_register_id'],
        ]);
    }

    /**
     * Reglas de validación de una caja.
     *
     * @param Model|null $current Caja en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:80'],
            'requires_session' => ['sometimes', 'boolean'],
        ];

        if ($current === null) {
            $rules['branch_id'] = ['required', 'uuid', Rule::exists(Branch::class, 'id')->where('status', 'active')];
        }

        return $rules;
    }
}
