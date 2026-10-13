<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de proveedores: los mismos terceros, filtrados y creados como proveedor.
 */
class SupplierController extends PartyController
{
    /**
     * Regla de borrado: sin compras registradas.
     */
    protected function guard(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Compras registradas' => ['table' => 'purch.purchase_documents', 'column' => 'supplier_id'],
        ]);
    }

    /**
     * Marca el tercero como proveedor al crearlo.
     *
     * @param array<string, mixed> $data Datos validados.
     * @return array<string, mixed>
     */
    protected function extraOnCreate(array $data): array
    {
        return ['is_supplier' => true, 'is_customer' => (bool) ($data['is_customer'] ?? false)];
    }

    /**
     * Lista solo proveedores.
     *
     * @param Request $request Admite `q` y `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $request->query->set('scope', 'supplier');

        return parent::index($request);
    }
}
