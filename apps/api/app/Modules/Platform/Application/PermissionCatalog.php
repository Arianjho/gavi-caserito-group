<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

/**
 * Catálogo de permisos del sistema, definido en código.
 *
 * Cada permiso tiene la forma `modulo.recurso.accion`. El catálogo es la
 * única fuente de verdad: los perfiles solo guardan códigos de esta lista.
 */
final class PermissionCatalog
{
    /**
     * Recursos con las acciones estándar de gestión (ver, crear, editar, eliminar).
     *
     * @var list<string>
     */
    private const CRUD_RESOURCES = [
        'catalog.item', 'catalog.category', 'catalog.brand', 'catalog.attribute', 'catalog.price',
        'parties.customer', 'parties.supplier',
        'orders.quotation', 'orders.order',
        'sales.document', 'sales.note',
        'purchases.document', 'purchases.expense',
        'inventory.adjustment', 'inventory.transfer', 'inventory.count',
        'shipping.shipment', 'shipping.carrier',
        'payments.collection', 'payments.payment',
        'settings.company', 'settings.branch', 'settings.warehouse', 'settings.user', 'settings.role',
        'settings.payment', 'settings.template', 'settings.notification',
        'fiscal.series', 'cash.register',
    ];

    /**
     * Acciones estándar de gestión.
     *
     * @var list<string>
     */
    private const CRUD_ACTIONS = ['view', 'create', 'update', 'delete'];

    /**
     * Permisos especiales que no siguen el patrón estándar.
     *
     * @var list<string>
     */
    private const SPECIAL = [
        'sales.pos.use',
        'sales.document.void',
        'orders.order.cancel',
        'orders.order.confirm',
        'catalog.item.deactivate',
        'catalog.item.merge',
        'catalog.label.print',
        'parties.customer.merge',
        'inventory.stock.view',
        'inventory.movement.view',
        'inventory.stock.adjust_cost',
        'payments.receivable.view',
        'payments.payable.view',
        'payments.collection.void',
        'cash.session.view',
        'cash.session.open',
        'cash.session.close',
        'cash.movement.view',
        'cash.movement.create',
        'shipping.picking.view',
        'shipping.picking.update',
        'shipping.label.print',
        'fiscal.document.view',
        'fiscal.document.emit',
        'fiscal.queue.view',
        'fiscal.queue.retry',
        'fiscal.dispatch.view',
        'fiscal.dispatch.emit',
        'settings.printing.update',
        'reports.view',
        'reports.export',
        'import.run',
        'audit.log.view',
    ];

    /**
     * Devuelve todos los códigos de permiso, ordenados.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $codes = self::SPECIAL;
        foreach (self::CRUD_RESOURCES as $resource) {
            foreach (self::CRUD_ACTIONS as $action) {
                $codes[] = "{$resource}.{$action}";
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * Indica si un código pertenece al catálogo.
     *
     * @param string $code Código a verificar.
     */
    public static function exists(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    /**
     * Permisos que concede cada plantilla de perfil del sistema.
     *
     * `*` significa todos. Un comodín `modulo.*` concede todo el módulo.
     *
     * @return array<string, list<string>>
     */
    public static function templates(): array
    {
        return [
            'administrator' => ['*'],
            'seller' => [
                'catalog.item.view', 'parties.customer.*', 'orders.quotation.*', 'orders.order.view',
                'orders.order.create', 'orders.order.update', 'orders.order.confirm', 'sales.pos.use',
                'sales.document.view', 'sales.document.create', 'payments.collection.create',
                'payments.collection.view', 'payments.receivable.view', 'inventory.stock.view',
                'shipping.shipment.view',
            ],
            'cashier' => [
                'sales.pos.use', 'catalog.item.view', 'parties.customer.view', 'parties.customer.create',
                'sales.document.view', 'sales.document.create', 'payments.collection.*',
                'cash.session.*', 'cash.movement.*',
            ],
            'warehouse' => [
                'catalog.item.view', 'inventory.*', 'shipping.*', 'orders.order.view',
            ],
        ];
    }

    /**
     * Expande comodines de una plantilla a códigos concretos del catálogo.
     *
     * @param list<string> $patterns Códigos o comodines (`*`, `modulo.*`, `modulo.recurso.*`).
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $all = self::all();
        $result = [];
        foreach ($patterns as $pattern) {
            $result = array_merge($result, self::match($pattern, $all));
        }

        return array_values(array_unique($result));
    }

    /**
     * Códigos del catálogo que coinciden con un patrón.
     *
     * @param string $pattern Código exacto, `*` o prefijo terminado en `.*`.
     * @param list<string> $all Catálogo completo.
     * @return list<string>
     */
    private static function match(string $pattern, array $all): array
    {
        if ($pattern === '*') {
            return $all;
        }
        if (! str_ends_with($pattern, '.*')) {
            return in_array($pattern, $all, true) ? [$pattern] : [];
        }
        $prefix = substr($pattern, 0, -1);

        return array_values(array_filter($all, fn (string $code): bool => str_starts_with($code, $prefix)));
    }
}
