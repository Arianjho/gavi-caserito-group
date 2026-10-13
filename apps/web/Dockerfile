# syntax=docker/dockerfile:1
# SPA de Gavi Caserito: se compila con Vite y se sirve con nginx, que además
# reenvía /api a la API (mismo origen, sin CORS).

FROM node:22-alpine AS build
WORKDIR /app
RUN npm install -g pnpm@10 --no-fund --no-audit
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile
COPY . .
RUN pnpm exec vite build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
