<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

use Illuminate\Database\Eloquent\Model;

/**
 * Cambia el estado de vida de un registro: activar, desactivar o eliminar
 * lógicamente, respetando la regla de borrado de su dominio.
 */
final class LifecycleService
{
    /**
     * Elimina lógicamente un registro si no tiene dependencias.
     *
     * @param Model $model Registro a eliminar.
     * @param DeletionGuard $guard Regla de borrado del dominio.
     * @param string|null $reason Motivo del borrado.
     *
     * @throws RecordInUseException Si existen dependencias que lo impiden.
     */
    public function delete(Model $model, DeletionGuard $guard, ?string $reason = null): void
    {
        $blockers = array_filter($guard->blockers($model), fn (int $count): bool => $count > 0);

        if ($blockers !== []) {
            throw new RecordInUseException($blockers, $guard->canDeactivate());
        }

        $model->forceFill([
            'status' => 'deleted',
            'deleted_at' => now(),
            'deleted_by' => request()->user()?->getAuthIdentifier(),
            'deletion_reason' => $reason,
        ])->save();
    }

    /**
     * Desactiva un registro: deja de ofrecerse en nuevas operaciones pero conserva su historial.
     *
     * @param Model $model Registro a desactivar.
     */
    public function deactivate(Model $model): void
    {
        $model->forceFill(['status' => 'inactive'])->save();
    }

    /**
     * Reactiva un registro inactivo.
     *
     * @param Model $model Registro a activar.
     */
    public function activate(Model $model): void
    {
        $model->forceFill(['status' => 'active'])->save();
    }
}
