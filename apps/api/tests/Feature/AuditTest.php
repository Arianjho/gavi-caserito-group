<?php

declare(strict_types=1);

use App\Modules\Platform\Application\ProvisionAccount;
use App\Modules\Platform\Models\Role;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('registra la creacion y los cambios en la bitacora', function (): void {
    $result = app(ProvisionAccount::class)->handle('Cuenta X', 'Duena', 'x@example.test', 'clave-12345');

    TenantContext::asProvider(function () use ($result): void {
        $role = Role::query()->where('tenant_id', $result['tenant']->id)->where('base_template', 'seller')->firstOrFail();
        $role->update(['description' => 'Atiende mostrador']);

        $entry = DB::table('core.audit_log')
            ->where('entity_id', $role->id)->where('action', 'updated')->first();

        expect($entry)->not->toBeNull();
        expect(json_decode((string) $entry->changes, true))
            ->toHaveKey('description')
            ->and(DB::table('core.audit_log')->where('entity_id', $role->id)->where('action', 'created')->exists())->toBeTrue();
    });
});

it('no guarda contrasenas en la bitacora', function (): void {
    $result = app(ProvisionAccount::class)->handle('Cuenta X', 'Duena', 'x@example.test', 'clave-12345');

    TenantContext::asProvider(function () use ($result): void {
        $entry = DB::table('core.audit_log')->where('entity_id', $result['owner']->id)->first();

        expect((string) $entry->changes)->not->toContain('password');
    });
});

it('impide modificar o borrar la bitacora', function (): void {
    app(ProvisionAccount::class)->handle('Cuenta X', 'Duena', 'x@example.test', 'clave-12345');

    TenantContext::asProvider(function (): void {
        $intento = fn () => DB::transaction(fn () => DB::table('core.audit_log')->delete());
        expect($intento)->toThrow(QueryException::class);
    });
});
