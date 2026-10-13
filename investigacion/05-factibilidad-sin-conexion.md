# Factibilidad del modo "Sin conexión"

Fecha: 2026-10-11. Análisis técnico propio (no basado en búsqueda web). Las limitaciones de navegadores deben verificarse con pruebas en equipos reales.

## 1. Veredicto
**Factible por niveles. No como "todo el sistema sin internet".** Recomendación: v1 diseñado para ello y con degradación segura; v1.5 con venta POS sin conexión acotada.

## 2. Niveles
| Nivel | Qué es | Factibilidad | Esfuerzo | Recomendación |
|---|---|---|---|---|
| 0 | Reintento ante cortes breves, sin pérdida de datos | Alta | Bajo | **v1** |
| 1 | POS: **venta al contado** sin conexión, con catálogo en el equipo y cola de sincronización | Media-alta | Medio-alto | **v1.5** |
| 2 | Cobros, abonos, caja y cierre sin conexión | Media | Alto | v2 |
| 3 | Pedidos, compras, envíos, ajustes y reportes sin conexión | Baja | Muy alto | No |

## 3. Qué hace falta para el nivel 1
1. **PWA** instalable con *service worker* y almacenamiento local (IndexedDB). Ayuda que la SPA no use SSR.
2. **Copia local acotada:** ítems, variantes, códigos de barras, precios, clientes frecuentes, medios de pago y configuración de la sucursal. No todo el catálogo si es enorme; se define un tope y una sincronización incremental.
3. **Identificadores generados en el equipo** (UUID v7) y `Idempotency-Key` por venta: la sincronización puede reintentarse sin duplicar.
4. **Numeración provisional:** número local por equipo (ej. `T-EQ03-000127`). Al sincronizar se asigna el definitivo.
5. **Endpoint de sincronización por lotes** que procesa ventas en orden, reporta resultado por venta y es idempotente.
6. **Acceso sin red:** sesión con vigencia ampliada y verificación por PIN local; permisos y límites se evalúan con la copia local y se **revalidan al sincronizar**.
7. **Cola visible** con estados: pendiente, sincronizada, rechazada (con motivo y acción).

## 4. Problemas difíciles (y cómo se tratan)
| Problema | Tratamiento propuesto |
|---|---|
| **Stock real desconocido** | Se vende contra la última copia; al sincronizar puede quedar negativo. Política configurable: permitir y marcar "revisar", o limitar por stock local |
| **Comprobante electrónico sin conexión** | El correlativo lo asigna eFact y SUNAT exige comunicación. Sin conexión solo se puede registrar **nota de venta / ticket provisional**; la boleta o factura se emite al reconectar. SUNAT tiene regímenes de contingencia: validar con un contador si el negocio los necesita |
| **Dos equipos vendiendo el último artículo** | Aceptar ambas; el conflicto se registra y se trata como ajuste |
| **Precio o permiso cambiado mientras estaba desconectado** | Se respeta lo que el equipo conocía; se marca la diferencia en la sincronización |
| **Reloj del equipo incorrecto** | Se guarda hora local y se reconcilia con la del servidor al sincronizar |
| **Pérdida del equipo con ventas pendientes** | Las ventas pendientes solo existen ahí; se avisa del riesgo y se sincroniza apenas haya señal |
| **Caja y arqueo** | El turno debe abrirse con conexión; el cierre exige sincronizar antes |
| **Navegador** | Se recomienda Chrome. En iOS todos los navegadores usan WebKit, así que Chrome no elimina las limitaciones de almacenamiento persistente ni de sincronización en segundo plano: en iOS el nivel 1 no está garantizado. Objetivo principal: Chrome en Android y escritorio |
| **Actualización de la aplicación** | Control de versión del esquema local y migraciones de IndexedDB |

## 5. Qué NO se permite sin conexión
- Emitir comprobantes electrónicos, anulaciones fiscales.
- Cobros de cuentas por cobrar y cambios de crédito.
- Pedidos y envíos (pueden registrarse como borrador local en v2).
- Cambios de configuración, permisos o catálogo.

## 6. Qué preparar desde v1 (barato ahora, caro después)
1. Identificadores UUID v7 y `Idempotency-Key` en todo caso de uso que cambia estado.
2. Numeración de documentos con **serie por equipo** posible.
3. API con lotes y respuestas por elemento.
4. Frontend con una capa de datos que separe "lo que se ve" de "lo que se envía" (cola de mutaciones).
5. Documentos con `origen`, `equipo_id` y `creado_en_equipo` (hora local).
6. Indicador de conexión y estado "pendiente de sincronizar" en el diseño visual.

## 7. Relación con el bloqueo de formularios
En línea: el formulario se bloquea hasta la respuesta. Sin conexión: el guardado local es inmediato, el botón dice "Guardar en este equipo" y el resultado es "pendiente de sincronizar". Son dos caminos del mismo componente.

## 8. Riesgos
- Complejidad de conflictos mal comunicada al usuario.
- Cuentas cuadradas en caja con ventas sin sincronizar.
- Dependencia del navegador del cliente; hay que probar con equipos económicos reales.

## 9. Decisión sugerida
Registrar en D19: nivel 0 en v1; nivel 1 en v1.5 solo para venta al contado de POS y ticket interno; niveles 2 y 3 fuera de alcance.
