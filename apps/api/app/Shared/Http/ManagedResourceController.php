<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Http\Controllers\Controller;
use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\LifecycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base para maestros con ciclo de vida activo/inactivo/eliminado.
 *
 * Aporta listado, alta, edición, activación y eliminación lógica con
 * guardia de dependencias. Cada maestro define su modelo, sus reglas de
 * validación y su regla de borrado.
 */
abstract class ManagedResourceController extends Controller
{
    /**
     * Clase del modelo Eloquent administrado.
     *
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * Regla de borrado del maestro.
     */
    abstract protected function guard(): DeletionGuard;

    /**
     * Reglas de validación para crear o editar.
     *
     * @param Model|null $current Registro que se edita; nulo al crear.
     * @return array<string, mixed>
     */
    abstract protected function rules(?Model $current): array;

    /**
     * Valores adicionales a guardar al crear (por ejemplo, claves derivadas).
     *
     * @param array<string, mixed> $data Datos validados.
     * @return array<string, mixed>
     */
    protected function extraOnCreate(array $data): array
    {
        return [];
    }

    /**
     * Datos del registro para la respuesta.
     *
     * @param Model $model Registro a presentar.
     * @return array<string, mixed>
     */
    protected function present(Model $model): array
    {
        return $model->only(array_merge(['id', 'status', 'version'], array_keys($this->rules($model))));
    }

    /**
     * Lista los registros no eliminados.
     *
     * @param Request $request Admite `status` (active o inactive) como filtro.
     */
    public function index(Request $request): JsonResponse
    {
        $class = $this->modelClass();
        $query = $class::query()->where('status', '!=', 'deleted')->orderBy('created_at');

        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (Model $model): array => $this->present($model))->values(),
        ]);
    }

    /**
     * Crea un registro en la cuenta del usuario.
     *
     * @param Request $request Datos del registro.
     */
    public function store(Request $request): JsonResponse
    {
        $class = $this->modelClass();
        $data = $request->validate($this->rules(null));

        $model = $class::create(array_merge(
            $data,
            $this->extraOnCreate($data),
            ['tenant_id' => $request->user()?->tenant_id],
        ));

        return response()->json(['data' => $this->present($model->refresh())], 201);
    }

    /**
     * Actualiza un registro.
     *
     * @param Request $request Datos a actualizar.
     * @param string $id Identificador del registro.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $model = $this->find($id);
        $model->fill($request->validate($this->rules($model)))->save();

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * Activa o desactiva un registro.
     *
     * @param Request $request Petición con `active` (booleano).
     * @param string $id Identificador del registro.
     * @param LifecycleService $lifecycle Servicio de ciclo de vida.
     */
    public function setActive(Request $request, string $id, LifecycleService $lifecycle): JsonResponse
    {
        $active = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];
        $model = $this->find($id);

        $active ? $lifecycle->activate($model) : $lifecycle->deactivate($model);

        return response()->json(['data' => $this->present($model->refresh())]);
    }

    /**
     * Elimina lógicamente un registro sin dependencias.
     *
     * @param Request $request Petición con `reason` opcional.
     * @param string $id Identificador del registro.
     * @param LifecycleService $lifecycle Servicio de ciclo de vida.
     */
    public function destroy(Request $request, string $id, LifecycleService $lifecycle): JsonResponse
    {
        $lifecycle->delete($this->find($id), $this->guard(), $request->input('reason'));

        return response()->json(['message' => 'Registro eliminado.']);
    }

    /**
     * Busca un registro no eliminado o responde 404.
     *
     * @param string $id Identificador del registro.
     */
    protected function find(string $id): Model
    {
        $class = $this->modelClass();

        return $class::query()->where('status', '!=', 'deleted')->findOrFail($id);
    }
}
