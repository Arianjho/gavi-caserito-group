# 06 · Mercado y precios (Perú, octubre 2026)

> Estado: **propuesta para decidir**. Los precios de terceros salen de comparativas web (algunas de
> terceros, con fecha y IGV dispares): hay que reconfirmarlos en cada sitio antes de publicar tarifas.

## 1. Qué cobra la competencia (S/ al mes)

| Producto | Entrada | Qué incluye la entrada | Escalón con inventario / POS | Fuente |
|---|---|---|---|---|
| **Alegra Facturación** | 49 | 20 comprobantes, 2 usuarios, IGV incluido | 59 (60 cpe) · 99 (150) · 199 (350, 8 usuarios) | Web oficial |
| **Alegra POS** | 81 | 2 usuarios, sin tope de ventas | 93 con bodegas | Comparativa |
| **Nubefact** | 29–70 | Solo emisión; ~50 comprobantes en el plan base; sin ERP | — | Comparativas (dispersas) |
| **Mifact** | 45 | Emisión; 2 puntos de venta | 70 con inventario | Comparativa |
| **Keyfacil** | 33.9 + IGV | Plan gratis de 30 documentos al mes | almacenes incluidos | Comparativa |
| **Susii** | 69 (39 NRUS) | Comprobantes ilimitados | — | Comparativa |
| **Wally** | 49 + IGV | Hasta S/ 30,000 de ventas al mes | 99 + IGV | Comparativa |
| **Bsale** | 189 | Solo documentos de venta | **233** con inventario y POS | Comparativa (sep-2026) |
| **INVY** | 169 | 500 comprobantes, POS e inventario | — | Comparativa |
| **Treinta** (app) | ~US$ 9–20 | Gestión móvil; factura electrónica en el plan Pro | — | Capterra/GetApp |

Lectura:
1. **Emisión sola** cuesta S/ 30–70. Es una mercancía: no se compite por ahí.
2. **Gestión (inventario + POS + varios usuarios) real** empieza en ~S/ 80–100 y sube a S/ 170–230 con
   cupos de 150–500 comprobantes. Los cupos publicados son bajos (20–350).
3. Nadie publica en esa franja **pedidos con anticipo, seguimiento al cliente, envíos por agencia,
   cobranzas y marca blanca** juntos. Es el hueco de Gavi Caserito.
4. Las microempresas comparan contra **S/ 40–60** (Nubefact, Mifact, Treinta). Subir de ahí exige
   mostrar el flujo de pedido→envío→cobro, no solo la boleta.

## 2. Nuestro costo (Gavi eFact, propio)

| Plan eFact | Precio | Comprobantes/mes | Sucursales | Guías (GRR/GRT) |
|---|---|---|---|---|
| Emprendedor | 30 | 200 | 1 | no |
| Negocio | 59 | 1,000 | 3 | sí |
| Empresa | 130 | 5,000 | 10 | sí |

- Es **un plan eFact por empresa (RUC)**, así que una empresa adicional cuesta al menos S/ 30.
- No hay excedente en eFact: al llegar al cupo responde 403. Por eso el cupo del plan debe ser
  menor o igual al de eFact.
- Otros costos: servidor, WhatsApp (Evolution propio), correo, soporte. No cuantificados aquí;
  asumir ~S/ 8–12 por cuenta activa hasta medirlos.

## 3. Propuesta de planes (IGV incluido, como el mercado)

| | **Inicio** | **Negocio** | **Empresa** |
|---|---|---|---|
| Precio mensual | **S/ 69** | **S/ 129** | **S/ 249** |
| Comprobantes electrónicos | 200 | 1,000 | 5,000 |
| Empresas (RUC) | 1 | 1 (+ adicionales) | 3 |
| Sucursales | 1 | 3 | 10 |
| Usuarios | 2 | 5 | 15 |
| Ventas, POS, pedidos, cotizaciones, caja | sí | sí | sí |
| Inventario, cobranzas, compras | sí | sí | sí |
| Envíos y seguimiento al cliente | sí | sí | sí |
| Guías de remisión | — | sí | sí |
| WhatsApp (avisos y deudas) | — | sí | sí |
| Marca blanca (logo, color, nombre) | — | sí | sí |
| Dominio propio | — | — | sí |
| Costo eFact | 30 | 59 | 130 |
| Margen bruto aprox. (sin IGV, antes de infra) | ~49 % | ~46 % | ~38 % |

- **Anual:** pagar 10 meses (−17 %) para fijar el cliente y adelantar caja.
- **Adicionales:** empresa extra S/ 59 (cubre su plan eFact), usuario extra S/ 10, paquete de 500
  comprobantes S/ 25.
- **Prueba:** 14 días con funciones de Negocio y 50 comprobantes (igual que eFact).
- **Beta (amigos):** sin cobro mientras dure; plan `beta` ya existente.
- **Posicionamiento:** Inicio ofrece 200 comprobantes con inventario y POS por S/ 69; Alegra pide
  S/ 99 por 150 sin ese conjunto de módulos, y Bsale S/ 233. Negocio compite con INVY (169, 500 cpe) y
  Bsale (233), con el doble de cupo y pedidos/envíos.

## 4. Riesgos y qué validar antes de publicar

1. **Sensibilidad al precio:** el mercado micro paga ≤ S/ 60. Validar con los 2 amigos de la beta y
   con 5–10 comercios más si S/ 69 se percibe como «el sistema completo».
2. **Cupo por empresa vs por cuenta:** hoy el cupo se cuenta por cuenta; eFact lo cuenta por empresa.
   Con varias empresas, repartir el cupo o contarlo por RUC.
3. **Costo real de infraestructura y WhatsApp** por cuenta.
4. **Cobro:** manual por ahora (decisión previa); medir cuánto tiempo toma antes de integrar pasarela
   (comisión ~3.5 % + S/ 1 reduce el margen de Inicio a ~43 %).
5. **Competidor con plan gratis (Keyfacil, 30 documentos):** considerar un plan «Gratis» de 30
   comprobantes solo si el costo de eFact lo permite (hoy no: S/ 30 de piso).

## 5. Qué ya está implementado para soportarlo
- Cupo mensual por cuenta (`max_documents_month`) sobre facturas, boletas y notas de crédito del mes,
  sin contar rechazados; aviso desde el 80 % y bloqueo de nuevas emisiones al 100 %, sin perder la venta.
- Límites de usuarios y empresas; panel del proveedor para fijar plan y topes.
- Falta: catálogo de planes con funciones por plan (guías, WhatsApp, marca blanca, dominio) y cobro.
