<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de clientes: los mismos terceros, filtrados como cliente.
 */
class CustomerController extends PartyController
{
    /**
     * Lista solo clientes.
     *
     * @param Request $request Admite `q` y `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $request->query->set('scope', 'customer');

        return parent::index($request);
    }
}
