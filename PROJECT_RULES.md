# Project Overview: Gig Economy Profitability & Efficiency Platform

## Core Purpose
Cross-platform system designed to help gig economy drivers (Uber, DiDi, Lyft) maximize their profitability by calculating real-time shift efficiency, fuel costs, vehicle depreciation, and net earnings. Future roadmap includes evolving into a proprietary ride-hailing app.

## Tech Stack & Architecture
- **Backend:** Laravel 11 (API-only architecture) running on PostgreSQL.
- **Authentication:** Laravel Sanctum (API Tokens for Mobile & Web SPA).
- **Mobile App:** React Native (Cross-platform Android & iOS) using NativeWind for styling and React Native Reanimated / Gesture Handler for interactive UX elements (e.g., draggable floating status button).
- **Web Dashboard:** Vue.js 3 using Tailwind CSS, PrimeVue (DataTable, UI), and ApexCharts.
- **Local OCR Engine:** Vision API / ML Kit (Mobile) & Tesseract (Backend fallback) for reading physical fuel invoices.

## Coding Standards & Rules
1. **Language Standard:** ALL code, database schemas, migration files, variable names, method definitions, API endpoints, and code comments MUST be written exclusively in **English**.
2. **Database Schema Strategy:** Highly normalized relational architecture.
   - `daily_shifts`: Tracks automated metrics (GPS km, connected time, scanned offers) and calculated net metrics (applied fuel cost, depreciation, real net profit).
   - `daily_earnings`: Relational table mapping earnings per platform (Uber, DiDi, etc.) linked to a daily shift.
   - `fuel_invoices`: Manages uploaded fuel physical receipts and asynchronous OCR data states.
3. **UI/UX Guidelines:** Strict visual parity between Web and Mobile using unified Tailwind/NativeWind color tokens (`#10B981` Emerald for profitable, `#F59E0B` Amber for neutral, `#EF4444` Rose for loss, `#0F172A` Slate dark background).

## Current Project Status
- Initial Laravel 11 scaffold completed.
- Database connection configured.
- Migrations created and executed: `daily_shifts`, `daily_earnings`, `fuel_invoices`.
- Active Task: Creating Eloquent Models with relationships and developing the `ShiftController` for shift closures and real-time profitability calculations.




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