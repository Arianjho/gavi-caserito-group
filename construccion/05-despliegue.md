# Despliegue con Docker

Un solo `docker-compose.prod.yml` levanta todo menos la base de datos (PostgreSQL es un servicio aparte).

| Servicio | Imagen | Función |
|---|---|---|
| `web` | `apps/web/Dockerfile` (Vite → nginx) | Sirve la SPA y reenvía `/api` a la API (mismo origen) |
| `api` | `apps/api/Dockerfile` (FrankenPHP) | API Laravel; aplica migraciones al arrancar |
| `queue` | misma imagen que `api` | Cola: WhatsApp y facturación electrónica |
| `scheduler` | misma imagen que `api` | Reconciliador fiscal (cada 10 min) y recordatorio de deudas (09:00) |
| `redis` | `redis:7-alpine` | Cola, caché y sesiones |

## Puesta en marcha

```
cp .env.docker.example .env.docker     # completa APP_KEY, DB_* y URLs
docker compose -f docker-compose.prod.yml --env-file .env.docker up -d --build
```

La web queda en `http://localhost:${WEB_PORT}`. La API no se publica: solo se llega por `/api`.

## Base de datos

- Crea la base y un rol **sin superusuario ni `BYPASSRLS`** que sea dueño de ella; si no, el aislamiento por cuenta (RLS) no se aplica.
- Apunta `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` en `.env.docker`.
- Desde Docker Desktop, un PostgreSQL del equipo es `host.docker.internal`.

## Notas

- `APP_KEY` es obligatoria (el contenedor no arranca sin ella). Cámbiala y se invalidan los tokens y las credenciales cifradas (eFact, WhatsApp): guárdala bien.
- Solo `api` ejecuta migraciones (`RUN_MIGRATIONS=true`); `queue` y `scheduler` no.
- HTTPS: pon un proxy inverso (Caddy, Traefik o el de tu hosting) delante de `web` y ajusta `APP_URL` y `FRONTEND_URL`.
- Verificado: las imágenes construyen, el stack levanta y `GET /api/v1/health`, el ingreso y la SPA responden 200.
