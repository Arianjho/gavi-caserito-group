<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de la bitácora de auditoría de la cuenta (solo lectura).
 */
class AuditLogController extends Controller
{
    /**
     * Lista eventos recientes con el nombre de quien los realizó.
     *
     * @param Request $request Admite `entity_type`, `actor_id`, `from`, `to` y `page`.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entity_type' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = DB::table('core.audit_log as a')
            ->leftJoin('core.users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.tenant_id', $request->user()?->tenant_id)
            ->orderByDesc('a.id')
            ->select(['a.id', 'a.action', 'a.entity_type', 'a.entity_id', 'a.changes', 'a.ip_address',
                'a.created_at', 'u.name as actor_name']);

        if (! empty($filters['entity_type'])) {
            $query->where('a.entity_type', 'like', '%'.$filters['entity_type'].'%');
        }
        if (! empty($filters['actor_id'])) {
            $query->where('a.actor_id', $filters['actor_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('a.created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('a.created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $page = $query->paginate(100);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
