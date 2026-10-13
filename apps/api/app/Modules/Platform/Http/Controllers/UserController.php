<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\PlanLimits;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Shared\Lifecycle\LifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * Usuarios de la cuenta: alta con perfiles, edición, activación y cambio de
 * contraseña. El propietario no puede desactivarse ni perder su acceso total.
 */
class UserController extends Controller
{
    /**
     * Lista los usuarios con sus perfiles.
     */
    public function index(): JsonResponse
    {
        $users = User::query()->where('status', '!=', 'deleted')->orderBy('name')->get();
        $roles = DB::table('core.user_roles as ur')
            ->join('core.roles as r', 'r.id', '=', 'ur.role_id')
            ->get(['ur.user_id', 'r.id', 'r.name'])->groupBy('user_id');

        return response()->json([
            'data' => $users->map(fn (User $user): array => $this->present($user, $roles->get($user->id)?->all() ?? []))->values(),
        ]);
    }

    /**
     * Crea un usuario con los perfiles indicados.
     *
     * @param Request $request Datos del usuario.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class, 'email')->whereNot('status', 'deleted')],
            'password' => ['required', 'string', 'min:10', 'max:100'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['uuid', Rule::exists(Role::class, 'id')->where('status', 'active')],
        ]);

        app(PlanLimits::class)->ensureCanCreate((string) $request->user()?->tenant_id, 'users');

        $user = DB::transaction(function () use ($data, $request): User {
            $user = User::create([
                'tenant_id' => $request->user()?->tenant_id, 'name' => $data['name'],
                'email' => strtolower($data['email']), 'password' => $data['password'],
            ]);
            $this->syncRoles($user, $data['role_ids'], (string) $request->user()?->tenant_id);

            return $user;
        });

        return response()->json(['data' => $this->presentWithRoles($user)], 201);
    }

    /**
     * Actualiza nombre, perfiles y, opcionalmente, la contraseña.
     *
     * @param Request $request Datos a actualizar.
     * @param string $id Usuario.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'password' => ['nullable', 'string', 'min:10', 'max:100'],
            'role_ids' => [$user->is_owner ? 'nullable' : 'required', 'array'],
            'role_ids.*' => ['uuid', Rule::exists(Role::class, 'id')->where('status', 'active')],
        ]);

        DB::transaction(function () use ($user, $data, $request): void {
            $user->name = $data['name'];
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();

            if (! $user->is_owner && ! empty($data['role_ids'])) {
                $this->syncRoles($user, $data['role_ids'], (string) $request->user()?->tenant_id);
            }
            if (! empty($data['password'])) {
                $user->tokens()->delete();
            }
        });

        return response()->json(['data' => $this->presentWithRoles($user->refresh())]);
    }

    /**
     * Activa o desactiva un usuario; al desactivar se cierran sus sesiones.
     *
     * @param Request $request Petición con `active`.
     * @param string $id Usuario.
     * @param LifecycleService $lifecycle Servicio de ciclo de vida.
     */
    public function setActive(Request $request, string $id, LifecycleService $lifecycle): JsonResponse
    {
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $user = User::query()->findOrFail($id);

        if ($user->is_owner) {
            return response()->json([
                'code' => 'owner_protected', 'message' => 'El propietario de la cuenta no puede desactivarse.',
            ], 422);
        }
        if ($user->getKey() === $request->user()?->getAuthIdentifier()) {
            return response()->json([
                'code' => 'self_deactivation', 'message' => 'No puedes desactivar tu propio usuario.',
            ], 422);
        }

        if ($active) {
            $lifecycle->activate($user);
        } else {
            $lifecycle->deactivate($user);
            $user->tokens()->delete();
        }

        return response()->json(['data' => $this->presentWithRoles($user->refresh())]);
    }

    /**
     * Reemplaza los perfiles del usuario.
     *
     * @param User $user Usuario.
     * @param list<string> $roleIds Perfiles asignados.
     * @param string $tenantId Cuenta.
     */
    private function syncRoles(User $user, array $roleIds, string $tenantId): void
    {
        DB::table('core.user_roles')->where('user_id', $user->id)->delete();
        foreach (array_unique($roleIds) as $roleId) {
            DB::table('core.user_roles')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'role_id' => $roleId,
                'created_by' => request()->user()?->getAuthIdentifier(),
            ]);
        }
    }

    /**
     * Presenta un usuario consultando sus perfiles.
     *
     * @param User $user Usuario.
     * @return array<string, mixed>
     */
    private function presentWithRoles(User $user): array
    {
        $roles = DB::table('core.user_roles as ur')->join('core.roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)->get(['r.id', 'r.name'])->all();

        return $this->present($user, $roles);
    }

    /**
     * Datos públicos del usuario.
     *
     * @param User $user Usuario.
     * @param array<int, stdClass> $roles Perfiles (id y nombre).
     * @return array<string, mixed>
     */
    private function present(User $user, array $roles): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->status,
            'is_owner' => $user->is_owner, 'last_login_at' => $user->last_login_at,
            'roles' => array_map(fn (stdClass $r): array => ['id' => $r->id, 'name' => $r->name], $roles),
        ];
    }
}
