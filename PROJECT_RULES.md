# Project Overview: Gig Economy Profitability & Efficiency Platform

## Core Purpose
Cross-platform system designed to help gig economy drivers (Uber, DiDi, Lyft) maximize their profitability by calculating real-time shift efficiency, fuel costs, vehicle depreciation, and net earnings. Future roadmap includes evolving into a proprietary ride-hailing app.

## Tech Stack & Architecture
- **Backend:** Laravel 13 (API-only architecture) running on PostgreSQL. This repository.
- **Authentication:** Laravel Sanctum personal access tokens (bearer tokens) for both the Web SPA and the Mobile app.
- **Mobile App:** React Native (Cross-platform Android & iOS) using NativeWind for styling and React Native Reanimated / Gesture Handler for interactive UX elements (e.g., draggable floating status button).
- **Web Dashboard:** Vue.js 3 + Vite using Tailwind CSS, PrimeVue, and ApexCharts.
- **Local OCR Engine:** Vision API / ML Kit (Mobile) & Tesseract (Backend fallback) for reading physical fuel invoices.

### Repository Split
This project holds **only** the database schema, Eloquent models, business logic, jobs and the JSON API. It renders no application UI — the sole browser route is the informational landing page at `/` describing the service and its owner. The UI lives in two separate repositories that consume this API:

| Repository | Consumer |
| --- | --- |
| `ride-efficiency-api` | Database + JSON API (this repo) |
| `ride-efficiency-frontend` | Vue 3 + PrimeVue web dashboard |
| `ride-efficiency-mobile` | React Native mobile application |

### API Authentication Flow
1. `POST /api/v1/auth/token` with `email`, `password` and an optional `device_name` returns a plain-text bearer token.
2. Consumers send `Authorization: Bearer <token>` on every protected request.
3. `DELETE /api/v1/auth/token` revokes the token used for the request.

All `/api/v1/*` endpoints except token issuance require the `auth:sanctum` middleware. CORS origins are controlled by `CORS_ALLOWED_ORIGINS`.

## Coding Standards & Rules
1. **Language Standard:** ALL code, database schemas, migration files, variable names, method definitions, API endpoints, and code comments MUST be written exclusively in **English**.
2. **Database Schema Strategy:** Highly normalized relational architecture.
   - `daily_shifts`: Tracks automated metrics (GPS km, connected time, scanned offers) and calculated net metrics (applied fuel cost, depreciation, real net profit).
   - `daily_earnings`: Relational table mapping earnings per platform (Uber, DiDi, etc.) linked to a daily shift.
   - `fuel_invoices`: Manages uploaded fuel physical receipts and asynchronous OCR data states.
3. **UI/UX Guidelines:** Strict visual parity between Web and Mobile using unified Tailwind/NativeWind color tokens (`#10B981` Emerald for profitable, `#F59E0B` Amber for neutral, `#EF4444` Rose for loss, `#0F172A` Slate dark background). These guidelines apply to the frontend and mobile repositories, not here.

## Current Project Status
- Laravel 13 API-only service on PostgreSQL.
- Domain migrations in place: `users`, `countries`, `daily_shifts`, `daily_earnings`, `fuel_invoices`, `personal_access_tokens`.
- Eloquent models with relationships and factories/seeders for every entity.
- Sanctum bearer-token authentication for the web and mobile consumers.
- JSON API v1 complete for shifts, fuel invoices and statistics/efficiency metrics.
- The former Inertia/Vue dashboard and Fortify web auth flow were extracted to `ride-efficiency-frontend`.

## Spanish / Español
Este repositorio es **únicamente** la base de datos y la API JSON. No contiene interfaz de usuario salvo la página informativa en `/`. El dashboard web vive en `ride-efficiency-frontend` y la app móvil en `ride-efficiency-mobile`; ambos se autentican con tokens Sanctum obtenidos en `POST /api/v1/auth/token`.




# Project Rules (Spanish)

## Resumen del Proyecto
Sistema para calcular la rentabilidad de conductores de plataformas (Uber, DiDi, Lyft) mediante seguimiento de kilometraje, tiempo conectado y gestión de recibos de gasolina. El objetivo es maximizar la eficiencia y ganancia neta del conductor.

## Pila Tecnológica
- **Backend:** Laravel 11 (API).
- **Base de Datos:** PostgreSQL.
- **Autenticación:** Laravel Sanctum.
- **App Móvil:** React Native (Android/iOS) con NativeWind.
- **Dashboard Web:** Vue.js 3 con TailwindCSS y PrimeVue.
- **OCR Local:** ML Kit (móvil), Tesseract (backend).

## Estándares y Convenciones
- **Idioma:** TODO el código, migraciones, modelos, rutas, comentarios y documentación debe estar **exclusivamente en inglés**.
- **Estructura de Base de Datos:**
    - `daily_shifts`: Métricas automáticas (km GPS, tiempo conectado, ofertas escaneadas) y métricas netas calculadas (costo de gasolina aplicado, depreciación, ganancia neta real).
    - `daily_earnings`: Earnings por plataforma (Uber, DiDi, etc.) vinculados a un turno diario.
    - `fuel_invoices`: Recibos de gasolina físicos y estados de procesamiento OCR.
- **UI/UX:** Paridad visual estricta entre Web y Móvil usando tokens unificados de NativeWind (`#10B981` Esmeralda para ganancia, `#F59E0B` Ámbar para neutral, `#EF4444` Rosa para pérdida, fondo oscuro `#0F172A`).

## Estado Actual
- Scaffold inicial de Laravel 11 completado.
- Conexión a base de datos configurada.
- Migraciones creadas y ejecutadas: `daily_shifts`, `daily_earnings`, `fuel_invoices`.
- Tarea actual: Creación de modelos Eloquent con relaciones y desarrollo del `ShiftController` para cierres de turno y cálculos de rentabilidad en tiempo real.