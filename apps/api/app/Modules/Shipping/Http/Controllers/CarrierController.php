<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers;

use App\Modules\Shipping\Models\Carrier;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Gestión de agencias de transporte.
 */
class CarrierController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Carrier::class;
    }

    /**
     * Regla de borrado: una agencia con envíos se desactiva, no se elimina.
     */
    protected function guard(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Envíos con la agencia' => ['table' => 'ship.shipments', 'column' => 'carrier_id'],
        ]);
    }

    /**
     * Reglas de validación de una agencia.
     *
     * @param Model|null $current Agencia en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['agency', 'courier', 'own', 'pickup'])],
            'tracking_url_template' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
