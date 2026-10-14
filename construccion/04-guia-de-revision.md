# Guía de revisión

Para revisar todo lo construido sin perderse. Estado detallado: `03-estado-actual.md`.

## Cómo levantar (un solo comando)

```
docker compose up -d        # Redis + API + cola + planificador + web
docker compose logs -f api web
docker compose down
```

- Web: http://127.0.0.1:5173 (el panel del proveedor está en `/operador`) · API: http://127.0.0.1:8000.
- Usa el PostgreSQL de tu equipo (puerto 5432, base `caserito`); la API migra y siembra al arrancar.
- Si tenías `php artisan serve` o `vite` corriendo en el equipo, deténlos: usan los mismos puertos.
- La primera vez instala dependencias dentro de los contenedores (tarda unos minutos).

Sin Docker, a mano: `php artisan serve`, `queue:work`, `schedule:work` en `apps/api` y `npx vite` en `apps/web`.

Usuario de desarrollo del seeder: `dev@caserito.test` / `caserito-dev` (solo local).

## Calidad

```
cd apps/api && composer quality     # pint + phpstan 8 + phpcs + pest
cd apps/web && pnpm quality && pnpm build
```

## Recorrido sugerido (flujo de tu juguetería)

1. **Catálogo:** productos → variantes (talla/color), atributos, listas de precios, importar Excel, etiquetas.
2. **Inventario:** saldo inicial, kardex, traslado entre almacenes, toma física, valor del inventario.
3. **Venta:** POS (ventas en espera, lista de precios del cliente), cobro mixto, devolución parcial.
4. **Pedido:** cotización → pedido (reserva) → editar → armar → entregar parcial → cobrar; cancelar y devolver anticipo.
5. **Envío:** agencia, estados, enlace público de seguimiento (con tu marca), aviso por WhatsApp.
6. **Cobranza:** por cobrar, cobro repartido entre deudas, estado de cuenta, recordatorio manual y automático.
7. **Compras:** compra con ingreso de stock, pago parcial, devolución al proveedor, gastos por rubro.
8. **Caja:** turno, arqueo, caja chica.
9. **Facturación:** configurar Gavi eFact, comprobantes, bandeja de pendientes.
10. **Administración:** usuarios, perfiles y permisos, auditoría, plan y límites, marca.
11. **Panel del proveedor:** `/operador` (operador de desarrollo `operador@caserito.test` / `caserito-operador`): crear cuenta, plan y límites, suspender y reactivar.

## Cosas que NO están probadas contra servicios reales

- Gavi eFact (solo respuestas simuladas): verificar payload, descuentos, cuotas y host en beta.
- Evolution API / WhatsApp (solo respuestas simuladas).
- Importación de un `.xlsx` real y lectura de código de barras con lector físico.

## Pendiente conocido

Notas de crédito y baja fiscales, aprovisionamiento por bridge, portal del cliente con cuenta, marca blanca
completa (dominio y logo), guía de remisión, picking con ubicaciones, lotes/series, staging y precios/planes.
