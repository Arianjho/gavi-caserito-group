# Mercado: negocios de volumen con pedidos y envíos (Gamarra, Centro de Lima)

Fecha: 2026-10-11. Método: 7 búsquedas web. **Evidencia débil**: casi no hay blogs que describan la operación interna de Gamarra; lo hallado son páginas comerciales de tiendas, blogs de proveedores de software y notas de prensa. Marcado como [Fuente] lo que viene de la web, [Inferido] lo que es razonamiento mío y debe validarse con entrevistas.

## 1. Cómo operan hoy (fuentes)
- Pedidos por **WhatsApp, Instagram y TikTok**; el asesor confirma stock y flete por mensaje. [Fuente: tiendas de Gamarra]
- Precios por **unidad, docena, ciento o millar**. [Fuente]
- **Anticipo del 50 %** para confirmar y saldo antes del envío; pago por transferencia o Yape. Algunas ofrecen **pago en destino**. [Fuente]
- Despacho por **agencias** (Shalom, Marvisur, Olva, Chexpress, Sanky, Grael…) elegida por el cliente; plazos de 6 a 48 h o 2–4 días según destino. [Fuente]
- Alertas de **guías de transportista falsas** de agencias: el cliente desconfía de la guía. [Fuente: Chexpress]
- Los paros y bloqueos (2022–2023) cortaron la entrega a provincias; Gamarra estimó pérdidas de S/ 200 millones. [Fuente: prensa]
- En distribución, el problema típico es el **doble registro**: pedido por WhatsApp → alguien lo reescribe en el sistema → errores de cantidad, marca o precio; y la entrega queda en mensajes y papel, difícil de probar ante reclamo. [Fuente: blogs de gestión de distribución]
- Preventa (toma pedido, entrega después) y autoventa (vende y cobra en la visita) son flujos distintos que un sistema de distribución debe soportar. [Fuente]

## 2. Datos regulatorios que afectan al diseño
- La **GRE remitente** sustenta el traslado; GRE transportista la emite el transportista. [Fuente]
- Hay **fuentes contradictorias** sobre fechas: una indica obligatoriedad para todos desde 01/07/2026 y otra menciona prórroga hasta 31/10/2026; umbral citado: S/ 2,750 (media UIT 2026). **Verificar en SUNAT antes de diseñar el módulo.**

## 3. Integración con transportistas
- **Olva Courier:** entrega token + documentación API tras solicitud y portal de gestión (fuente secundaria, confirmar con Olva). Cobertura > 1,500 destinos. Integrado en AfterShip.
- **Shalom:** API históricamente limitada, depende del tipo de cuenta.
- **Cruz del Sur:** número de control (NIC, 9 dígitos); integrado con Tiendanube; API de remitente por confirmar.
- **Marvisur:** sin documentación pública encontrada.
- Agregadores multi-courier: Envíame (16 couriers en Perú), AfterShip. Cobertura exacta no verificada.
- **Conclusión:** seguimiento automático por agencia no es confiable para v1. Debe nacer **manual** (la tienda registra guía/clave y avanza estados) con puerto `CarrierTracker` para integrar después.

## 4. Lo que no se encontró
- Blogs peruanos sobre el dolor interno de mayoristas de Gamarra (capacidad de armado, errores de pedido, cobranza). Requiere **entrevistas con 5–10 comerciantes**.
- Conciliación de cobro contra entrega y rendición del repartidor.
- Precios y comparativas de competidores peruanos para este segmento.

## 5. Fuentes
- Gestión, Serpost en Gamarra: https://gestion.pe/economia/empresas/serpost-refuerza-su-presencia-en-gamarra-estamos-mapeando-la-ubicacion-estrategica-envios-nacional-comerciantes-delivery-campana-navidena-noticia/
- Expreso, pérdidas de Gamarra: https://www.expreso.com.pe/economia/gamarra-protestas-en-peru/
- Chexpress agencia Gamarra: https://chexpress.pe/agencias/agencia-gamarra/
- Gamarra Mayoristas, servicio: https://gamarramayoristas.com/nuestro-servicio/
- Softland, ERP para distribuidoras y GRE: https://softland.com/pe/erp-para-distribuidoras-en-peru/ · https://softland.com/pe/guia-de-remision-electronica-en-peru/
- Perugestiona, GRE 2026: https://perugestiona.pe/?p=1278
- Yo Facturo, preventa/autoventa y reparto: https://yo-facturo.com/blog/sistema-preventa-autoventa/ · https://yo-facturo.com/blog/organizar-reparto-negocio/
- StelOrder, gestión de distribución: https://www.stelorder.com/blog/gestionar-distribucion/
- Kom.pe, integrar Olva y Shalom: https://kom.pe/como-integrar-olva-courier-y-shalom-en-tu-tienda-virtual/
- AfterShip Olva: https://www.aftership.com/integrations/carriers/olva-courier
- Tiendanube/Cruz del Sur: https://ayuda.tiendanube.com/cruz-del-sur/preguntas-frecuentes-sobre-cruz-del-sur
- Revista Economía, última milla: https://www.revistaeconomia.com/distribucion-urbana-y-ultima-milla-en-peru/
