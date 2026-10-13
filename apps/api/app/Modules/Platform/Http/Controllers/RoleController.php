<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\PermissionCatalog;
use App\Modules\Platform\Application\RoleDeletionGuard;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\RolePermission;
use App\Modules\Platform\Models\User;
use App\Shared\Lifecycle\LifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestión de perfiles de acceso y de su catálogo de permisos.
 */
class RoleController extends Controller
{
    /**
     * Lista el catálogo de permisos disponibles.
     */
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => PermissionCatalog::all()]);
    }

    /**
     * Lista los perfiles de la cuenta con sus permisos.
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->where('status', '!=', 'deleted')
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => $this->present($role));

        return response()->json(['data' => $roles]);
    }

    /**
     * Crea un perfil con sus permisos.
     *
     * @param Request $request Datos: `name`, `description`, `permissions` y `denied`.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        /** @var User $user */
        $user = $request->user();

        $role = DB::transaction(function () use ($data, $user): Role {
            $role = Role::create([
                'tenant_id' => $user->tenant_id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $this->syncPermissions($role, $data['permissions'] ?? [], $data['denied'] ?? []);

            return $role;
        });

        return response()->json(['data' => $this->present($role->load('permissions'))], 201);
    }

    /**
     * Actualiza nombre, descripción y permisos de un perfil.
     *
     * Los perfiles de plantilla del sistema solo permiten cambiar permisos
     * si no son el de administrador.
     *
     * @param Request $request Datos a actualizar.
     * @param string $id Identificador del perfil.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $role = Role::query()->where('status', '!=', 'deleted')->findOrFail($id);
        $data = $this->validated($request, $role);

        if ($role->base_template === 'administrator') {
            return response()->json([
                'code' => 'role_protected',
                'message' => 'El perfil Administrador no se puede modificar.',
            ], 422);
        }

        DB::transaction(function () use ($role, $data): void {
            $role->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ])->save();
            $this->syncPermissions($role, $data['permissions'] ?? [], $data['denied'] ?? []);
        });

        return response()->json(['data' => $this->present($role->refresh()->load('permissions'))]);
    }

    /**
     * Elimina (lógicamente) un perfil sin usuarios asignados.
     *
     * @param Request $request Petición con `reason` opcional.
     * @param string $id Identificador del perfil.
     * @param LifecycleService $lifecycle Servicio de ciclo de vida.
     */
    public function destroy(Request $request, string $id, LifecycleService $lifecycle): JsonResponse
    {
        $role = Role::query()->where('status', '!=', 'deleted')->findOrFail($id);

        if ($role->is_system) {
            return response()->json([
                'code' => 'role_protected',
                'message' => 'Los perfiles del sistema no se pueden eliminar.',
            ], 422);
        }

        $lifecycle->delete($role, new RoleDeletionGuard, $request->input('reason'));

        return response()->json(['message' => 'Perfil eliminado.']);
    }

    /**
     * Desactiva o reactiva un perfil.
     *
     * @param Request $request Petición con `active` (booleano).
     * @param string $id Identificador del perfil.
     * @param LifecycleService $lifecycle Servicio de ciclo de vida.
     */
    public function setActive(Request $request, string $id, LifecycleService $lifecycle): JsonResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $role = Role::query()->where('status', '!=', 'deleted')->findOrFail($id);

        if ($role->is_system) {
            return response()->json([
                'code' => 'role_protected',
                'message' => 'Los perfiles del sistema no se pueden desactivar.',
            ], 422);
        }

        $active ? $lifecycle->activate($role) : $lifecycle->deactivate($role);

        return response()->json(['data' => $this->present($role->refresh()->load('permissions'))]);
    }

    /**
     * Valida los datos de un perfil.
     *
     * @param Request $request Petición con los datos.
     * @param Role|null $ignore Perfil actual (al editar) para la regla de nombre único.
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Role $ignore = null): array
    {
        $codes = Rule::in(PermissionCatalog::all());

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', $codes],
            'denied' => ['sometimes', 'array'],
            'denied.*' => ['string', $codes],
        ]);
    }

    /**
     * Reemplaza los permisos de un perfil.
     *
     * @param Role $role Perfil a actualizar.
     * @param list<string> $allowed Códigos concedidos.
     * @param list<string> $denied Códigos denegados.
     */
    private function syncPermissions(Role $role, array $allowed, array $denied): void
    {
        RolePermission::query()->where('role_id', $role->id)->delete();

        foreach (array_unique($allowed) as $code) {
            RolePermission::create([
                'tenant_id' => $role->tenant_id, 'role_id' => $role->id,
                'permission_code' => $code, 'effect' => 'allow',
            ]);
        }
        foreach (array_unique($denied) as $code) {
            RolePermission::create([
                'tenant_id' => $role->tenant_id, 'role_id' => $role->id,
                'permission_code' => $code, 'effect' => 'deny',
            ]);
        }
    }

    /**
     * Datos de un perfil para la respuesta.
     *
     * @param Role $role Perfil con permisos cargados.
     * @return array<string, mixed>
     */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'status' => $role->status,
            'permissions' => $role->permissions->where('effect', 'allow')->pluck('permission_code')->values(),
            'denied' => $role->permissions->where('effect', 'deny')->pluck('permission_code')->values(),
        ];
    }
}
