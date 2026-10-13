<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Registra macros de `Blueprint` con las columnas estándar del proyecto.
 *
 * - `auditColumns()`: quién y cuándo creó o modificó un registro, y su versión.
 * - `lifecycle()`: estado de vida (`active`/`inactive`/`deleted`) y datos del borrado lógico.
 * - `appendOnlyAudit()`: columnas mínimas para tablas de solo anexado.
 *
 * Ver `diseno/03-modelo-de-datos.md` §18.
 */
final class BlueprintMacros
{
    /**
     * Registra las macros. Se invoca una vez al arrancar la aplicación.
     */
    public static function register(): void
    {
        Blueprint::macro('auditColumns', function (): void {
            /** @var Blueprint $this */
            $this->timestampTz('created_at')->useCurrent()->comment('Fecha y hora de creación (UTC)');
            $this->uuid('created_by')->nullable()->comment('Usuario que creó el registro; nulo si lo hizo el sistema');
            $this->timestampTz('updated_at')->useCurrent()->comment('Fecha y hora de la última modificación (UTC)');
            $this->uuid('updated_by')->nullable()->comment('Usuario que modificó el registro por última vez');
            $this->unsignedInteger('version')->default(1)->comment('Versión del registro para bloqueo optimista');
        });

        Blueprint::macro('lifecycle', function (): void {
            /** @var Blueprint $this */
            $this->string('status', 16)->default('active')->comment('Estado de vida: active, inactive o deleted (eliminado lógico)');
            $this->timestampTz('deleted_at')->nullable()->comment('Fecha y hora del borrado lógico');
            $this->uuid('deleted_by')->nullable()->comment('Usuario que eliminó el registro');
            $this->text('deletion_reason')->nullable()->comment('Motivo del borrado lógico');
        });

        Blueprint::macro('appendOnlyAudit', function (): void {
            /** @var Blueprint $this */
            $this->timestampTz('created_at')->useCurrent()->comment('Fecha y hora de creación (UTC)');
            $this->uuid('created_by')->nullable()->comment('Usuario que creó el registro; nulo si lo hizo el sistema');
        });
    }
}
