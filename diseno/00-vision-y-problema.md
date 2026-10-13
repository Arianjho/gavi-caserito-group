# Visión y problema

Fecha: 2026-10-11. Estado: borrador para validar.

## Problema
Los negocios pequeños de Perú repiten a diario procesos que no tienen digitalizados: registrar ventas, cobrar (contado y fiado), controlar stock, cerrar caja, pagar a proveedores y entregar comprobante. Hoy lo hacen en cuaderno, Excel o un sistema que no entienden. Consecuencias:

- No saben cuánto vendieron, cuánto les deben ni cuánto deben.
- Pierden stock y dinero en caja sin saber por qué.
- Emitir comprobante electrónico les parece un trámite técnico (certificado, clave SOL, series).
- Cambiar de sistema es miedo: "tendría que volver a cargar todo".

## Propuesta
**Sistema de ventas SaaS, simple y rápido, que sigue el pedido del cliente de punta a punta**: del mensaje de WhatsApp a la entrega y el cobro. Vender, cobrar, controlar stock y caja desde el primer día; emitir a SUNAT cuando el negocio lo necesite, sin cambiar de sistema.

```
Pedido → Cotización → Anticipo/reserva → Armado → Venta/comprobante (opcional)
       → Envío (agencia, guía, clave) → Entrega → Saldo cobrado
```

El cliente del negocio puede ver el estado de su pedido en un portal simple y recibe avisos por WhatsApp.

## Público
1. **Primero:** negocios de día a día con volumen de pedidos y envíos (mayoristas tipo Gamarra y Centro de Lima, distribuidoras pequeñas) y emprendedores y tiendas que solo necesitan vender y controlar. Un solo producto multi-rubro: lo que cambia entre rubros se resuelve con atributos y variantes (talla, color, tamaño), no con versiones distintas.
2. **Después:** negocios medianos con varias sucursales, roles y más control.
3. **Pendiente:** validar con entrevistas a comerciantes (aún no hay contactos).

## Principios
1. **Venta primero, fiscal después.** La emisión SUNAT es opcional y no bloquea vender.
2. **Todo configurable por empresa:** caja con o sin apertura, stock sí o no, modo fiscal, crédito sí o no.
3. **Rápido para el que vende** (POS en pocos toques, lector de barras), **profundo para el que administra** (permisos, auditoría, reportes).
4. **Migrar es fácil:** importación guiada, plantillas, datos demo y tutoriales dentro de la app.
5. **Lenguaje de negocio, no de SUNAT.** Los códigos de catálogo existen pero quedan en vista avanzada.
6. **Nada se pierde:** anular o archivar, no borrar lo que tiene movimientos.

## Fuera de alcance (por ahora)
Contabilidad completa, planilla, producción, multi-país, cobro automático de suscripciones, pasarela de pagos.

## Cómo sabremos que funciona (a validar)
- Un negocio nuevo registra su primera venta en < 15 minutos desde el alta.
- Migración de catálogo de 500 productos en < 10 minutos.
- Una venta de POS en < 10 segundos con lector de barras.
- Cierre de caja sin diferencias inexplicadas visibles en el reporte.
- Porcentaje de comprobantes aceptados por SUNAT al primer intento.

## Relación con el resto de documentos
- Decisiones: `00-decisiones-base.md`
- Alcance de v1: `01-alcance-mvp.md`
- Referencias: `../investigacion/`
