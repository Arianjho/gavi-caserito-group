<?php

declare(strict_types=1);

namespace App\Shared\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Completa las columnas de auditoría estándar y registra la bitácora.
 *
 * - Al crear: `created_by` y `updated_by` con el usuario autenticado.
 * - Al actualizar: `updated_by` y suma 1 a `version`.
 * - Cada creación, cambio o borrado deja un evento en `core.audit_log`.
 *
 * Si no hay usuario (procesos del sistema) los campos de autor quedan nulos.
 */
trait HasAudit
{
    /**
     * Campos que nunca se copian a la bitácora.
     *
     * @var list<string>
     */
    private static array $auditHidden = ['password', 'remember_token'];

    /**
     * Registra los eventos de auditoría del modelo.
     */
    public static function bootHasAudit(): void
    {
        static::creating(function (Model $model): void {
            $actor = self::auditActor();
            if ($actor !== null) {
                $model->setAttribute('created_by', $actor);
                $model->setAttribute('updated_by', $actor);
            }
        });

        static::updating(function (Model $model): void {
            $model->setAttribute('updated_by', self::auditActor());
            $model->setAttribute('version', (int) $model->getOriginal('version', 1) + 1);
        });

        static::created(fn (Model $model) => self::writeAudit($model, 'created', self::cleanAttributes($model->getAttributes())));

        static::updated(function (Model $model): void {
            $changes = self::diff($model);
            if ($changes !== []) {
                self::writeAudit($model, 'updated', $changes);
            }
        });
    }

    /**
     * Calcula los campos de negocio que cambiaron (sin columnas técnicas).
     *
     * @param Model $model Modelo actualizado.
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private static function diff(Model $model): array
    {
        $technical = ['updated_at', 'updated_by', 'version'];
        $changes = [];
        foreach (self::cleanAttributes($model->getChanges()) as $field => $new) {
            if (! in_array($field, $technical, true)) {
                $changes[$field] = ['old' => $model->getOriginal($field), 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * Elimina los campos sensibles de un conjunto de atributos.
     *
     * @param array<string, mixed> $attributes Atributos del modelo.
     * @return array<string, mixed>
     */
    private static function cleanAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::$auditHidden));
    }

    /**
     * Inserta un evento en la bitácora.
     *
     * @param Model $model Modelo afectado.
     * @param string $action Acción realizada.
     * @param array<string, mixed> $changes Valores a registrar.
     */
    private static function writeAudit(Model $model, string $action, array $changes): void
    {
        DB::table('core.audit_log')->insert([
            'tenant_id' => $model->getAttribute('tenant_id') ?? ($model->getTable() === 'core.tenants' ? $model->getKey() : null),
            'actor_id' => self::auditActor(),
            'action' => $action,
            'entity_type' => $model->getTable(),
            'entity_id' => (string) $model->getKey(),
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            'ip_address' => app()->bound('request') ? request()->ip() : null,
        ]);
    }

    /**
     * Identificador del usuario autenticado en la petición actual.
     */
    private static function auditActor(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $id = request()->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
