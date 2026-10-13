# Estándares de código y buenas prácticas

Fecha: 2026-10-11. Estado: acordado en lo esencial; se afina al crear el repositorio. Aplica a todo el código del proyecto (API Laravel, SPA React, migraciones, pruebas).

## 1. Idioma
| Elemento | Idioma |
|---|---|
| Identificadores: clases, métodos, variables, tablas, columnas, rutas, códigos de error, claves de traducción, ramas y commits | **Inglés** |
| Documentación de código (PHPDoc, TSDoc, comentarios, `COMMENT ON` de base de datos) | **Español** |
| Interfaz (textos, mensajes, plantillas, correos, WhatsApp) | **Español (Perú)**, mediante archivos de traducción; el código solo referencia claves en inglés |
| Documentos de diseño y README | Español |

Ejemplos:
- Código de error: `stock_insufficient` → mensaje en el diccionario: "No hay stock suficiente de {item} (quedan {available})".
- Clave de traducción: `orders.confirm.success`.
- Mensaje de commit: `feat(orders): reserve stock on confirmation` (cuerpo opcional en español o inglés, consistente por repositorio).

## 2. Documentación obligatoria
Todo elemento público debe estar documentado en español. **Una clase, método o función sin documentación no pasa la revisión.**

### 2.1 Qué documentar
| Elemento | Contenido mínimo |
|---|---|
| Clase / interfaz / enum | Propósito, responsabilidad, colaboradores relevantes, invariantes |
| Método / función | Qué hace (no cómo), `@param` con significado y unidades, `@return`, `@throws` con la condición, efectos secundarios (eventos, colas, cobros) |
| Propiedad / constante | Significado y formato (ej. "importe en PEN con 2 decimales") |
| Migración | Qué cambia y por qué; `COMMENT ON TABLE/COLUMN` en español |
| Componente React / hook | Propósito, props, estados que maneja, ejemplo corto |
| Caso de uso | Precondiciones, permiso requerido, eventos que emite |

### 2.2 Ejemplo (PHP)
```php
/**
 * Confirma un pedido y reserva el stock de sus líneas.
 *
 * Reglas:
 *  - El pedido debe estar en borrador.
 *  - Si el stock disponible no alcanza y la política de la empresa lo impide, se rechaza.
 *
 * @param  ConfirmOrderCommand  $command  Datos del pedido a confirmar y el actor que lo solicita.
 * @return Order                          Pedido ya confirmado, con sus reservas creadas.
 *
 * @throws OrderNotDraftException         Si el pedido no está en borrador.
 * @throws InsufficientStockException     Si no hay stock disponible y la política lo prohíbe.
 */
public function __invoke(ConfirmOrderCommand $command): Order
```

### 2.3 Cómo se hace cumplir
- PHP: `phpcs` con reglas de comentarios obligatorios (clases, métodos, propiedades) y `phpstan` nivel 8 con extensión de PHPDoc estricto.
- TypeScript: `eslint-plugin-jsdoc` (`require-jsdoc`, `require-param`, `require-returns`) para funciones y componentes exportados.
- Base de datos: prueba automática que falla si una tabla o columna nueva no tiene `COMMENT`.
- CI bloquea la fusión si cualquiera falla.

## 3. Principios de diseño

### 3.1 SOLID aplicado al proyecto
| Principio | Qué significa aquí | Ejemplo concreto |
|---|---|---|
| **S** Responsabilidad única | Una clase, una razón de cambio | `ConfirmOrder` solo confirma; reservar stock lo hace `ReserveStock`; notificar lo hace un oyente de `OrderConfirmed` |
| **O** Abierto/cerrado | Se extiende con clases nuevas, sin editar las existentes | Nuevo transportista = nueva implementación de `CarrierTracker`; nueva regla de precio = nueva `PriceRule` registrada, sin tocar el resolvedor |
| **L** Sustitución de Liskov | Toda implementación cumple el contrato completo de su interfaz | `GaviEfactEngine` y un `FakeFiscalEngine` pasan la **misma** suite de pruebas de contrato |
| **I** Segregación de interfaces | Interfaces pequeñas y enfocadas | `FiscalEngine` (emitir), `FiscalStatusReader` (consultar), `FiscalFileProvider` (XML/PDF) separadas; no una interfaz gigante |
| **D** Inversión de dependencias | El dominio depende de abstracciones; la infraestructura las implementa | Los casos de uso reciben `FiscalEngine`, `Notifier`, `Clock`, `StockRepository`; el contenedor enlaza las implementaciones |

### 3.2 Otras prácticas que encajan
| Práctica | Aplicación |
|---|---|
| **Arquitectura hexagonal** (puertos y adaptadores) | Núcleo de dominio sin dependencia de Laravel; adaptadores para eFact, Evolution, almacenamiento |
| **DDD ligero** | Contextos = módulos; agregados (`Order`, `SalesDocument`, `Payment`, `StockLedger`); objetos de valor (`Money`, `Quantity`, `TaxId`, `DocumentNumber`); eventos de dominio |
| **Casos de uso / comandos** | Una clase por acción de negocio con actor explícito; los controladores no tienen reglas |
| **CQRS ligero** | Lecturas (listas, reportes, panel) con consultas dedicadas, separadas de los comandos |
| **Patrón Estrategia** | Cálculo de impuestos, resolución de precios, costeo, `DeletionGuard`, numeración |
| **Patrón Especificación** | Filtros reutilizables de listas y reportes |
| **Patrón Repositorio** | Solo donde aísla consultas complejas del dominio; no se envuelve Eloquent por reflejo |
| **Unidad de trabajo** | Una transacción por caso de uso; los eventos se publican por *outbox* dentro de ella |
| **Objeto de valor inmutable `Money`** | Importes como decimal exacto; prohibido `float` en dinero |
| **DTOs** | Entrada y salida de los casos de uso; no se pasan arreglos asociativos sueltos |
| **Políticas de autorización** | Permisos evaluados en el servidor (`Policy` / servicio de autorización), nunca solo en la interfaz |
| **Fail fast** | Validación al borde (Form Request / esquema) y aserciones en el dominio |
| **DRY / KISS / YAGNI** | Sin abstracciones "por si acaso"; se abstrae al segundo uso real |
| **Ley de Deméter** | Sin cadenas `$a->b()->c()->d()` atravesando módulos |
| **Composición sobre herencia** | Herencia solo para modelos y excepciones base |
| **Idempotencia** | `Idempotency-Key` en comandos que cambian estado; `external_id` hacia eFact |
| **Inmutabilidad** | Libros y documentos confirmados no se editan |
| **12 factores** | Configuración por entorno, registros a la salida estándar, procesos sin estado |

### 3.3 Reglas prácticas
- Un método hace una cosa y cabe en una pantalla (referencia: ≤ 30 líneas, complejidad ciclomática ≤ 10).
- Una clase ≤ 300 líneas; si crece, se divide por responsabilidad.
- Nada de lógica en controladores, modelos Eloquent ni componentes de vista que no sea de su capa.
- No se usa `static` ni fachadas dentro del dominio; se inyecta.
- Las excepciones de dominio son tipadas y llevan código (`stock_insufficient`).
- Sin *magic strings*: estados, tipos y códigos en `enum` / constantes.
- Tipos estrictos (`declare(strict_types=1)`; TypeScript `strict`).
- Prohibido `catch` vacío; se registra o se relanza con contexto.

## 4. Estructura de código (API)
```
app/
  Modules/
    Orders/
      Domain/            entidades, objetos de valor, eventos, interfaces (puertos)
      Application/       casos de uso, comandos, DTOs, consultas
      Infrastructure/    repositorios Eloquent, adaptadores, colas
      Http/              controladores, solicitudes, recursos
      Tests/
    Catalog/ Inventory/ Sales/ Payments/ Shipping/ ...
  Shared/                Money, Clock, Actor, Result, utilidades sin dominio
```
- Las dependencias van hacia adentro: `Http → Application → Domain`; `Infrastructure` implementa puertos del dominio.
- Un módulo solo usa el servicio público o los eventos de otro; una prueba de arquitectura (Pest Arch o Deptrac) verifica las fronteras.

## 5. Base de datos
- PostgreSQL; migraciones versionadas, atómicas y reversibles cuando sea posible.
- Cambios sin interrupción: añadir → migrar datos → cambiar código → eliminar, en despliegues distintos.
- Toda tabla y columna con `COMMENT` en español; tipos precisos; claves foráneas con `ON DELETE RESTRICT`.
- Índices con `tenant_id` primero; índices parciales para unicidad con `status <> 'deleted'`.
- Auditoría estándar y borrado lógico según `diseno/03-modelo-de-datos.md` §18.
- Sin consultas dentro de bucles; paginación por cursor; `EXPLAIN` en consultas de listas críticas.
- Semillas separadas por entorno; datos de catálogos SUNAT versionados.

## 6. API
- REST JSON versionada (`/api/v1`), recursos en inglés, errores uniformes `{code, message, fields, request_id}`.
- `message` ya viene en español desde el diccionario; `code` es estable.
- Validación en Form Requests; autorización en políticas; recursos de API para la salida.
- Idempotencia y paginación por cursor; cambios incompatibles = nueva versión.
- Especificación OpenAPI generada y publicada en la documentación.

## 7. Frontend
- TypeScript estricto; componentes pequeños y componibles; lógica fuera de la vista (hooks y servicios).
- Datos del servidor con TanStack Query; formularios con react-hook-form + zod (mismos códigos de error que la API).
- Componentes del contrato de interacción: `FormDialog`, `ConfirmDialog`, `ActionButton`, `useGuardedMutation` (ver `diseno/05-sistema-visual.md`).
- Textos solo mediante claves de traducción; ningún literal de interfaz en componentes.
- Accesibilidad (teclado, contraste AA, etiquetas) como criterio de aceptación.
- Estado global mínimo; el estado del servidor no se duplica en almacenes locales.

## 8. Pruebas
| Nivel | Qué cubre | Herramienta |
|---|---|---|
| Unitarias | Dominio, calculadoras, reglas | Pest / PHPUnit, Vitest |
| De contrato | Cada adaptador contra su interfaz (`FiscalEngine`, `Notifier`) | Suite compartida |
| De oro | `TaxCalculator` contra casos reales de eFact | Fixtures versionadas |
| De integración | Repositorios, migraciones, colas | Base de datos real de pruebas |
| De funcionalidad (API) | Flujos completos con permisos y tenants | Pest |
| De arquitectura | Fronteras entre módulos, reglas de nombres, documentación | Pest Arch / Deptrac |
| De interfaz | Componentes críticos y flujos de POS | Testing Library, Playwright |
| Aislamiento de tenants | Un tenant nunca ve datos de otro | Suite dedicada obligatoria |

- Cobertura objetivo: dominio ≥ 90 %; global ≥ 75 %.
- Una corrección de error empieza con una prueba que la reproduce.
- Datos de prueba con fábricas; sin dependencias de internet (servicios externos simulados).

## 9. Calidad automática (CI)
| Control | Herramienta |
|---|---|
| Formato | Laravel Pint, Prettier |
| Análisis estático | PHPStan/Larastan nivel 8, `tsc --noEmit` |
| Lint | ESLint (incluye jsdoc), phpcs (comentarios) |
| Seguridad | `composer audit`, `npm audit`, análisis de secretos |
| Pruebas | Todas las anteriores; migraciones sobre base limpia |
| Documentación | Comprobación de `COMMENT` de base de datos y de documentación de código |

Ninguna fusión a la rama principal con controles en rojo.

## 10. Control de versiones y revisión
- Rama principal protegida; ramas cortas por tarea; fusión por solicitud de revisión.
- Commits convencionales (`feat`, `fix`, `refactor`, `docs`, `test`, `chore`) en inglés.
- Versionado semántico de la API; registro de cambios (CHANGELOG).
- Lista de revisión: responsabilidad única, pruebas, documentación en español, permisos, aislamiento de tenant, migración reversible, manejo de errores, sin secretos.

## 11. Seguridad en el código
- Entrada siempre validada y salida escapada; consultas parametrizadas.
- Sin secretos en el repositorio; credenciales cifradas en reposo.
- Principio de mínimo privilegio en roles de base de datos y claves de integración.
- Registros sin datos sensibles (sin contraseñas, tokens ni documentos completos).
- Toda entrada del portal y del bot se trata como no confiable.
- Dependencias fijadas y revisadas.

## 12. Definición de "terminado"
Una tarea está terminada cuando:
1. Cumple el criterio de aceptación y los permisos.
2. Tiene pruebas (unitarias y, si aplica, de contrato o funcionales) en verde.
3. Todo lo público está documentado en español.
4. Los textos de interfaz están en archivos de traducción.
5. Pasa los controles de CI y la revisión.
6. Migraciones con `COMMENT` y probadas en limpio y en actualización.
7. Se actualizó la documentación de diseño si cambió una decisión.
