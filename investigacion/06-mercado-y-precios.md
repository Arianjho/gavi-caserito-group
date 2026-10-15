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
- Otros costos (servidor, IA, licencia, correo): ver la sección 6, tomados de la documentación de eFact.

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
| Marca blanca (logo, color, nombre) | sí | sí | sí |
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

## 5. Qué ya está implementado para soportarlo (ver también las secciones 6 y 7)
- Cupo mensual por cuenta (`max_documents_month`) sobre facturas, boletas y notas de crédito del mes,
  sin contar rechazados; aviso desde el 80 % y bloqueo de nuevas emisiones al 100 %, sin perder la venta.
- Límites de usuarios y empresas; panel del proveedor para fijar plan y topes.
- Falta: catálogo de planes con funciones por plan (guías, WhatsApp, dominio; la marca blanca va en todos) y cobro.

## 6. Costos reales compartidos con eFact

Fuente: `plataform-api-sunat/documentacion/26-Planes-y-Precios.md` y `20-Despliegue-VPS.md`
(octubre 2026, US$ 1 ≈ S/ 3.75, sin IGV).

| Concepto | S/ al mes | Comentario |
|---|---|---|
| Dos suscripciones de IA | 168 | 73 % del total; baja a la mitad con una sola cuando el proyecto esté estable |
| VPS Contabo (6 vCPU, 12 GB, 200 GB) | 27 | Sube a ≈ 34 tras 24 meses de promoción |
| Dominio | 3 | |
| Licencia anual de eFact (≈ 250 ÷ 12) | 21 | Solo del producto eFact |
| Correo transaccional y otros | 10 | |
| **Total fijo del grupo** | **≈ 229** | Infraestructura 40 + desarrollo 189 |

- **Es un solo costo fijo para los dos productos**: mismo VPS, mismas IAs. No se suma otro S/ 229.
  Para Gavi Caserito se reparte; con 50/50 le tocan ≈ S/ 115 (supuesto, no decisión).
- **Costo variable por cuenta ≈ S/ 2–3**: un emisor chico guarda ≈ 0.2 GB al año y usa RAM
  despreciable (sección 6 del doc de eFact). WhatsApp con Evolution suma RAM por número
  conectado: **no medido**; medir con 3–5 cuentas reales de la beta.
- **El S/ 30 / 59 / 130 de eFact es un precio de transferencia**, no caja: emitir un comprobante
  más cuesta casi cero. Sirve para ver el margen de cada producto por separado; el margen
  consolidado es mayor al de la tabla de la sección 3.
- **Punto de equilibrio consolidado**: con mezcla 30 % Gestión / 40 % Inicio / 25 % Negocio /
  5 % Empresa, el ingreso neto promedio es ≈ S/ 71 por cuenta (S/ 65 con tarjeta). Cubrir S/ 229 pide
  ≈ 4 cuentas; cubrir solo la mitad (S/ 115), 2. El costo que no aparece es **tu tiempo de soporte**.
- **Licencia**: el doc de eFact (sección 8) advierte que los términos de reventa y marca blanca
  no se verificaron. La marca blanca de Caserito y el servicio a terceros dependen de eso:
  revisarlo antes de cobrar.
- **El certificado digital y la clave SOL de cada cliente** son la mayor fricción de alta en eFact y
  un costo de soporte. Los clientes sin SUNAT no la tienen.

## 7. Plan para quienes aún no emiten comprobantes

Mucho comercio micro vende con notas o sin papel, sin certificado ni clave SOL y sin intención de
facturar todavía. Hoy el sistema los atiende (la venta queda como nota de venta) pero no hay un plan
pensado para ellos. Como no consumen eFact, su costo real es casi nulo y se puede competir donde
la facturación no deja margen.

| | **Gratis** | **Gestión** |
|---|---|---|
| Precio (IGV incl.) | S/ 0 | **S/ 39** |
| Comprobantes electrónicos | 0 (notas de venta) | 0 (notas de venta) |
| Usuarios / sucursales | 1 / 1 | 3 / 1 |
| Ventas al mes | hasta 50 | sin tope |
| Productos | hasta 100 | sin tope |
| POS, caja, inventario, clientes | sí | sí |
| Pedidos con anticipo y seguimiento al cliente | — | sí |
| Envíos por agencia y cobranzas | — | sí |
| Marca blanca (logo, color, nombre, contacto) | sí | sí |
| WhatsApp y dominio propio | — | — |
| Paso a facturar | subir a Inicio o Negocio | subir a Inicio o Negocio |
| Ingreso neto mensual | 0 | ≈ 33 |
| Costo real aproximado | ≈ 2 | ≈ 3 |

- **Frente a la competencia**: Keyfacil regala 30 documentos, Treinta cobra ≈ S/ 35–75 y Alegra POS
  S/ 81+. Gestión a S/ 39 incluye inventario y cobranzas; el plan Gratis evita que el comercio
  se quede con Keyfacil o con una libreta.
- **Por qué Gratis sí es viable aquí y no en eFact**: eFact tiene piso de S/ 30 por empresa; este plan no
  usa eFact y no lleva WhatsApp (el único costo escalable sin medir).
- **Embudo**: al cumplir con SUNAT suben a Inicio sin migrar datos. Hoy la API responde
  `document_quota_reached` si el cupo es 0; para estos planes conviene un código propio
  (`plan_without_invoicing`) y que la pantalla de venta ofrezca «Activar facturación».
- **Riesgos**: abuso de cuentas gratis (una por correo y teléfono, sin WhatsApp), soporte sin ingreso
  (solo guías), y canibalizar Inicio (S/ 69): por eso Gestión no trae guías, WhatsApp ni comprobantes.
  Revisar a los 90 días cuántas cuentas pasan a pago.
- **Escalera completa**: Gratis S/ 0 · Gestión S/ 39 · Inicio S/ 69 · Negocio S/ 129 · Empresa S/ 249.
- **Decisión**: la marca blanca (logo, color, nombre, contacto, pie) va en **todos** los planes,
  incluido Gratis. Solo el dominio propio queda en Empresa (requiere proxy y TLS).
