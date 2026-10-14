# Gavi Caserito

Sistema de ventas SaaS para negocios del día a día en Perú: pedidos, ventas, POS, inventario, caja, cobranzas, envíos y comprobantes electrónicos opcionales (mediante Gavi eFact).

> Estado: fase de preparación (Fase P). Ver `construccion/00-plan.md`.

## Estructura

```
apps/
  api/            API Laravel (monolito modular) · PostgreSQL · Redis
  web/            SPA React + Vite + TanStack Router (base: aura-admin-theme)
diseno/           Decisiones, alcance, arquitectura, modelo de datos, flujos y sistema visual
investigacion/    Referencias y análisis de mercado
construccion/     Plan de construcción y estándares de código
docker-compose.yml  Entorno local completo (API, cola, planificador, web y Redis; PostgreSQL y Mailpit opcionales)
docker-compose.prod.yml  Despliegue (ver construccion/05-despliegue.md)
```

## Requisitos
- PHP 8.3+ con `pdo_pgsql`, `bcmath`, `intl`, `mbstring`
- Composer 2
- PostgreSQL 17 (local o `docker compose --profile db up -d`)
- Docker (para Redis)
- Node 24 y pnpm/bun (para `apps/web`)

## Puesta en marcha de la API

```bash
docker compose up -d        # levanta todo: web :5380, API :8380, Redis :6390 (o solo Redis: docker compose up -d redis)

cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Base de datos de desarrollo: `caserito` (usuario propietario `caserito_owner`). Pruebas: `caserito_test`.
El usuario de ejecución `caserito_app` (sin privilegios de superusuario ni `BYPASSRLS`) se usará para el aislamiento por filas.

## Puesta en marcha de la web

```bash
cd apps/web
pnpm install
pnpm dev          # http://127.0.0.1:5173 (proxy /api → http://127.0.0.1:8000)
pnpm quality      # tipos, lint (incluye documentación) y formato
```

## Calidad

```bash
cd apps/api
composer quality      # formato, análisis estático, documentación y pruebas
composer format       # corrige el formato
composer analyse      # PHPStan nivel 8 (Larastan)
composer docs:check   # documentación obligatoria en español
composer test:pest    # pruebas
```

Los estándares completos están en `construccion/01-estandares-de-codigo.md`.

## Convenciones esenciales
- Código, tablas y rutas en inglés; documentación e interfaz en español.
- Todo elemento público documentado.
- Borrado lógico y auditoría estándar en toda tabla.
- Importes con aritmética decimal; cálculo compatible con eFact.
