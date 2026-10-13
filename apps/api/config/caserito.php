<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración propia de Gavi Caserito
|--------------------------------------------------------------------------
|
| Valores de negocio que no pertenecen al framework. Todo valor sensible
| debe venir de variables de entorno.
*/

return [
    /**
     * Zona horaria de negocio. Las fechas se guardan en UTC y se interpretan
     * en esta zona para fechas contables y de visualización.
     */
    'business_timezone' => env('CASERITO_BUSINESS_TIMEZONE', 'America/Lima'),

    /**
     * Moneda base del sistema (código ISO 4217).
     */
    'base_currency' => 'PEN',

    /**
     * Integración con el motor fiscal Gavi eFact. Las credenciales por empresa
     * se guardan cifradas en base de datos, no aquí.
     */
    'efact' => [
        'base_url' => env('EFACT_BASE_URL', 'https://apifact.gavi-cloud.com/api/v1'),
        'bridge_key' => env('EFACT_BRIDGE_KEY'),
        'timeout_seconds' => (int) env('EFACT_TIMEOUT_SECONDS', 20),
    ],

    /**
     * Integración con Evolution API para notificaciones por WhatsApp.
     */
    'evolution' => [
        'base_url' => env('EVOLUTION_BASE_URL'),
        'api_key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE'),
    ],
];
