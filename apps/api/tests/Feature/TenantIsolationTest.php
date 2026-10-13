<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verifica que la seguridad por filas aísle los datos entre cuentas.
 */
beforeEach(function (): void {
    DB::statement("SELECT set_config('app.scope', 'provider', false)");
    DB::table('core.tenants')->delete();
});

afterEach(function (): void {
    DB::statement("SELECT set_config('app.scope', '', false)");
    DB::statement("SELECT set_config('app.tenant_id', '', false)");
});

/**
 * Inserta una cuenta como proveedor y devuelve su id.
 *
 * @param string $name Nombre de la cuenta.
 */
function createTenant(string $name): string
{
    $id = (string) Str::uuid();
    DB::table('core.tenants')->insert(['id' => $id, 'name' => $name]);

    return $id;
}

it('muestra solo la cuenta activa', function (): void {
    $a = createTenant('Cuenta A');
    createTenant('Cuenta B');

    DB::statement("SELECT set_config('app.scope', '', false)");
    DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$a]);

    expect(DB::table('core.tenants')->pluck('name')->all())->toBe(['Cuenta A']);
});

it('no muestra nada sin contexto de cuenta', function (): void {
    createTenant('Cuenta A');
    DB::statement("SELECT set_config('app.scope', '', false)");

    expect(DB::table('core.tenants')->count())->toBe(0);
});

it('impide escribir filas de otra cuenta', function (): void {
    $a = createTenant('Cuenta A');
    DB::statement("SELECT set_config('app.scope', '', false)");
    DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$a]);

    $intento = fn () => DB::transaction(
        fn () => DB::table('core.tenants')->insert(['id' => (string) Str::uuid(), 'name' => 'Intrusa'])
    );

    expect($intento)->toThrow(QueryException::class);
});
