<?php

declare(strict_types=1);

/*
| Pruebas de arquitectura: protegen las reglas de diseño acordadas en
| construccion/01-estandares-de-codigo.md.
*/

arch('todo el código de la aplicación usa tipos estrictos')
    ->expect('App')
    ->toUseStrictTypes();

arch('no se dejan funciones de depuración')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray'])
    ->not->toBeUsed();

arch('los controladores no acceden a la base de datos directamente salvo el de salud')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB')
    ->ignoring('App\Http\Controllers\HealthController');
