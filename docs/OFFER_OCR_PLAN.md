# Plan — Lectura (OCR) de ofertas Uber / DiDi / inDrive

> **Estado:** propuesta v2 (modo plan, sin cambios de código aplicados). Decisiones cerradas:
> Tesseract por defecto, registro automático sin confirmación y extracción **nativa en mobile**.
> **Alcance:** API Laravel 13 (`/api/v1`) **y** `ride-efficiency-mobile` (extracción + cálculo
> local en el dispositivo). El API no es el camino crítico de la respuesta al conductor.
> **Fixtures:** `tests/oferta_uber_exclusiva.jpeg`, `tests/oferta_uber_viaje_disponible.jpeg`,
> `tests/oferta_didi.jpeg`, `tests/oferta_didi_exclusiva.jpeg`, `tests/oferta_lista_didi.jpeg`.

---

## 1. Revisión de la funcionalidad de ofertas actual

### 1.1 Lo que ya existe y funciona

| Capa    | Elemento                                                                                                                          | Notas                                                                                                                                                                                                                                                                                          |
| :------ | :-------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Datos   | `ride_offers`                                                                                                                     | 45 columnas: plataforma, `service_type`, `availability_type`, tarifa, surge, propina, peaje, distancias y duraciones (viaje y recogida), direcciones, rating/viajes/verificación del pasajero, `expires_at`, `source`, `ocr_confidence`, `raw_payload`, estados y veredictos cliente/servidor. |
| Datos   | `ride_offer_events`                                                                                                               | Funnel append-only con deduplicación por `(event_type, occurred_at)`.                                                                                                                                                                                                                          |
| Datos   | `daily_offer_metrics`                                                                                                             | Agregado diario por usuario (`AggregateDailyOfferMetricsJob`).                                                                                                                                                                                                                                 |
| API     | `POST /api/v1/ride-offers/sync`                                                                                                   | Upsert por lote (`client_uuid` idempotente), éxito parcial, eventos, recomputo server-side.                                                                                                                                                                                                    |
| API     | `POST /api/v1/ride-offers/evaluate`                                                                                               | Fallback sin persistencia.                                                                                                                                                                                                                                                                     |
| API     | `GET /ride-offers`, `GET /ride-offers/{id}`, `PATCH /ride-offers/{id}`                                                            | Filtros, paginación, máquina de estados (`received → evaluated → accepted/rejected/expired`, `accepted → completed/cancelled`).                                                                                                                                                                |
| API     | `GET /ride-offers/summary/daily`                                                                                                  | Funnel y promedios.                                                                                                                                                                                                                                                                            |
| Jobs    | `ExpireRideOffersJob` (cada minuto), `AggregateDailyOfferMetricsJob` (diario)                                                     | Registrados en `routes/console.php`.                                                                                                                                                                                                                                                           |
| Cálculo | `RecomputeRideOfferProfitabilityAction`                                                                                           | Usa `julioserpone/gig-economics`; requiere vehículo + precio de energía; `Platform::tryFrom()` ya soporta `uber`, `didi`, `indrive`, `lyft`, `custom`.                                                                                                                                         |
| OCR     | `ReceiptAnalysisProvider` + `ReceiptAnalysisProviderManager` + `AzureDocumentIntelligenceClient`                                  | Solo `prebuilt-receipt`; pensado para facturas.                                                                                                                                                                                                                                                |
| Tests   | `RideOfferSyncTest`, `RideOfferApiTest`, `RideOfferStateMachineTest`, `RideOfferLinkAndAggregateTest`, `OcrProcessingServiceTest` | Cobertura sólida del ciclo de vida; **sin OCR de ofertas**.                                                                                                                                                                                                                                    |

### 1.2 Huecos concretos (relevantes para esta tarea)

1. **No hay entrada de imagen para ofertas.** `source` admite `ocr`, `ocr_confidence` y
   `raw_payload` existen, pero no hay endpoint ni job que los produzca (ni desde la app
   móvil ni como respaldo del servidor).
2. **No hay taxonomía de tipos.** `platform` y `service_type` son `string(32)` libres, sin
   catálogo, sin normalización (p. ej. `UberX`, `uber_x`, `Uber X`), sin validación.
   `availability_type` tampoco tiene valores definidos (exclusivo / prioritario /
   viaje disponible / set your fare).
3. **No existe proveedor OCR de texto plano.** El contrato de proveedor devuelve JSON de
   `prebuilt-receipt`; Tesseract devuelve texto/TSV. Adaptar el proveedor es parte del plan.
4. **`sync` no deduplica capturas repetidas.** Si el cliente manda dos screenshots de la
   misma oferta con distinto `client_uuid`, se duplican.
5. **`ocr_confidence` es un escalar.** En una oferta la confianza es por campo
   (tarifa, distancias, tipo), no global.
6. **`expires_at` no se extrae.** Las ofertas caducan en segundos; hoy viene del cliente.

---

## 2. Validación empírica con Tesseract (hecha en esta revisión)

Entorno local: Tesseract **5.5.3** (eng, spa, osd), PHP 8.5 con `gd`, `imagick`, `bcmath`.

| Imagen                                       | `--psm 6` (bloque)                                                                                                                                                                     | `--psm 11` (texto disperso)    |
| :------------------------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------- |
| `oferta_uber_exclusiva.jpeg`                 | `$3.45`, `Identidad digital verificada`, `4.91 (377)`, `+$0.36 por inicio de viaje prioritario`, `11 min (6.3 km)`, `Sta. Elena, Llano Chico`, `Viaje: 17 min (7.6 km)`                | además detecta **`Exclusivo`** |
| `oferta_uber_viaje_disponible.jpeg`          | `$2.63`, `Identidad digital verificada`, `4.86 (168)`, `13 min (5.5 km)`, `Julio Yanez, Comite del Pueblo`, `Viaje: 14 min (5.8 km)`, `Viaje disponible`                               | igual                          |
| `oferta_didi_exclusiva.jpeg`                 | `Pago en efectivo`, `$1.56`, `4.78 · 41 viajes`, `7min (3,2km)`, `17 de Septiembre, 1335, Llano Chico`, `9min (5.4km)`, `Pizzería El Tablón, San Isidro del Inca`, `Rechazo permitido` | no recupera etiquetas adicionales |
| `oferta_didi.jpeg`                           | `Express Nuevo`, `4.88 · 414 viajes`, `(4 min 1.3 km) 23 de Abril, Calderon`, `(17 min 8.9 km) Avenida del Maestro & Melchor Toaza, Kennedy`                                           | ídem                           |
| `oferta_lista_didi.jpeg` (lista, sin oferta) | `Centro de viajes`, `Express Nuevo`, `No hay más solicitudes`                                                                                                                          | usa `psm 4`                    |

**Conclusiones observadas**

- **Sí sirve.** El texto de negocio se lee con buena calidad; el ruido es del **mapa**.
- **El ruido es espacial, no semántico:** letras sueltas de calles del mapa a la izquierda.
  Se filtra por posición (columna de inicio/longitud), no por contenido.
- **`psm 6` vs `psm 11`:** `psm 6` reproduce bien el orden de lectura del panel; `psm 11`
  recupera la etiqueta aislada `Exclusivo` de Uber que `psm 6` pierde. El plan usa
  **ambos** (`psm 6` para parsing y `psm 11` como refuerzo de etiquetas).
- **Colores de fondo ayudan:** el panel de oferta es casi blanco (`≈247–255`) y el mapa es
  azul claro (`≈244,246,255` con trama), lo que permite recortar la zona del panel.
- **La tipografía pequeña se pierde en la imagen completa.** Recortar + reescalar ×2–3
  fija `11 min` (que llegaba como `All min`), pero **`Exclusivo`** y signos como `%` siguen
  siendo frágiles. El tipo de servicio **no debe depender del OCR**: se detecta con la
  etiqueta y se confirma por contexto/color de la plataforma.
- **DiDi usa coma decimal** (`3,2km`) y separadores `·`; Uber usa `·` en el rating y `(6.3 km)`.
- **Riesgo de falsos positivos:** en `oferta_uber_exclusiva` hay una etiqueta de mapa
  `Ak 491 (377)` (POI), que coincide con el **patrón de rating** (`4.91 (377)`). Cualquier
  parser basado solo en regex sobre el rating puede confundirlas. Hay que exigir posición y
  coherencia (rating ≤ 5.00 + conteo en paréntesis + estar en el panel).

---

## 3. Taxonomía de plataformas y tipos de servicio

El contrato debe ser **estable** aunque el OCR falle. Propuesta de catálogo versionado en
`config/ride_offer_platforms.php` (no en BD: es configuración de dominio, no dato de usuario)
y expuesto por `GET /api/v1/ride-offers/catalog` para que **mobile** use exactamente los
mismos códigos canónicos que el servidor (una sola fuente de verdad, sin duplicar la lista
en TypeScript).

### 3.1 Plataformas (`platform`, valores que ya acepta `Platform`)

`uber`, `didi`, `indrive`, `lyft` (reservado), `custom`.

### 3.2 Uber (`service_type`)

UberX, **UberX Priority** (“Uber Priority”), **UberX Share** (“Uber Juntos”), Uber Comfort,
Uber Comfort Electric, Uber Black, Uber Black SUV, UberXL, Uber Pet, Uber Electric, Uber
Van, Uber Taxi, Uber Reserve, Uber Connect, Uber Intercity/Intercity (Interurbano),
Uber Moto (2 o 3 ruedas), Uber Auto (rickshaw). Fuente de referencia:
[Uber — Servicios y tipos de viaje](https://www.uber.com/ec/es/ride/ride-options/) y
[Uber — Ride options](https://www.uber.com/us/en/ride/ride-options/).

Normalización a `snake_case` canónico: `uberx`, `uberx_priority`, `uberx_share`,
`comfort`, `comfort_electric`, `black`, `black_suv`, `xl`, `pet`, `electric`, `van`,
`taxi`, `reserve`, `connect`, `intercity`, `moto`, `auto`.

### 3.3 DiDi (`service_type`)

`express` (Express / Express Nuevo / DiDi Express), `taxi` (DiDi Taxi),
`set_your_fare` (Set your fare — el conductor propone tarifa), `moto`, `comfort`,
`priority`/`programado`, `list` (**pantalla “Centro de viajes”, no es una oferta activa**).
Fuente: [DiDi Pasajero Ecuador](https://web.didiglobal.com/ec/pasajero/).
Para países con catálogo diferente (MX/BR/CO), el catálogo es por `country_code` opcional;
el valor desconocido cae a `custom:<texto normalizado>` y se registra como etiqueta.

### 3.4 inDrive (futuro)

`city` (tarifa negociada, el conductor propone), `intercity`, `courier`. No se implementa
lógica ahora: solo entra en el catálogo y en el contrato.

### 3.5 Tipo de asignación (`availability_type`)

Hoy es `string(32)` libre. Valores propuestos:

| Valor            | Señal en pantalla                                                         |
| :--------------- | :------------------------------------------------------------------------ |
| `exclusive`      | “Exclusivo” (Uber), “Exclusiva”, sin contador de otros conductores (DiDi) |
| `priority`       | “+$0.36 por inicio de viaje prioritario”, “Priority”                      |
| `available_trip` | “Viaje disponible” (Uber), lista de solicitudes sin asignación            |
| `set_your_fare`  | “Set your fare / Establece tu tarifa”                                     |
| `metered`        | DiDi Taxi (tarifa por taxímetro/estimada, no fija)                        |
| `unknown`        | No detectable                                                             |

---

## 4. Arquitectura propuesta

### 4.0 Flujo móvil-first (camino crítico, en el dispositivo)

Requisito: **mobile no espera al API** para mostrar el veredicto. La captura se procesa
nativamente y la imagen se envía después, en segundo plano.

```
mobile: detección de oferta (accesibilidad / notificación / screenshot)
   │
   ├─1─► RideOfferOcrService (nativo, ON-DEVICE)
   │       ├─ preproceso en el dispositivo (crop por color de panel + escala)
   │       ├─ OCR local: ML Kit Text Recognition (Android/iOS) o Tesseract nativo
   │       └─ parser por plataforma (misma spec de reglas que el servidor)
   │
   ├─2─► CalculateOfferProfitability (npm `gig-economics`, local, < 100 ms)
   │       └─ veredicto mostrado al conductor SIN red
   │
   └─3─► Cola local persistente (SQLite/AsyncStorage) → subida diferida
           POST /api/v1/ride-offers/ocr  (imagen + resultado local)   ── 202
             │
             ▼
     StoreRideOfferOcrRequest ── idempotente por `client_uuid` + `image_hash`
             │
             ▼
     RideOfferOcrScan (pending)
        ├─ UpsertRideOfferAction (inmediato en el 202: client_extraction o placeholder)
        └─ ProcessRideOfferOcrJob (cola, tesseract)
             ├─ PreprocessRideOfferImageAction   (GD/Imagick: crop panel, escala ×2.5)
             ├─ TesseractTextRecognitionProvider (psm 6 + psm 11 → tokens)
             ├─ ExtractRideOfferDataAction       (reglas por plataforma, confianza por campo)
             ├─ Reconciliación con el resultado local (ver §4.4)
             └─ Enriquecer la MISMA oferta (nunca se duplica; sin confirmación)
                │
                ▼
        RecomputeRideOfferProfitabilityAction (veredicto servidor)
```

**Regla de disponibilidad:** el veredicto mostrado al conductor **nunca** depende de la
red. Con el API caído, el móvil sigue extrayendo y calculando; la cola reintenta después.

### 4.4 Reconciliación móvil ↔ servidor

| Caso | Regla |
| :--- | :--- |
| El payload de `POST /ocr` trae `client_extraction` | Se persiste tal cual en `ride_offer_scans.client_extraction`; el servidor **no** confía en él para calcular. |
| OCR servidor con `confidence ≥ min_confidence` | Se comparan `offered_fare`, `estimated_distance_km`, `platform`. Si coinciden (tolerancia ±0.01 / ±0.05 km) → `confirmed`. |
| Divergencia en campos críticos | `discrepancy`, se conserva la extracción **del servidor** como autoritativa y se registra `discrepancies` (móvil vs servidor) para medir la precisión de ambos motores. |
| OCR del servidor ilegible | Se usa la extracción local (ya validada por el móvil) y se marca `source=ocr_mobile`; la oferta queda registrada igualmente. |
| Scan sin resultado local | Flujo puramente servidor (clientes web / respaldo). |
| Scan sin `client_extraction` (API caía al capturar) | Se registra con placeholder en el `202` y el job la completa; si el OCR falla queda `review_required` **con oferta registrada**. |

El objetivo de la reconciliación no es bloquear: es **reparar** datos y medir qué motor
acierta más por plataforma y versión de UI.

### 4.1 Reutilización de lo existente (sin reinventar)

- **Un nuevo contrato de proveedor de texto**: `TextRecognitionProvider::recognize(bytes, mime, options): OcrTextResult`
  con `tesseract` y `azure-read` (prebuilt-read) como implementaciones. El contrato
  `ReceiptAnalysisProvider`/`ReceiptDataExtractor` **no se toca** (sigue siendo facturas).
  Un `TextRecognitionProviderManager` sigue el mismo patrón que `ReceiptAnalysisProviderManager`.
- **Persistencia:** la imagen se guarda en `local` con el mismo patrón que `EnergyInvoiceController`
  (`ride_offers/{user_id}/{uuid}.jpg`), validando MIME y tamaño.
- **`sync` y la máquina de estados** se reutilizan: el OCR produce payload compatible con
  `SyncRideOffersRequest` y llama al mismo camino de upsert (extraído a un Action
  `UpsertRideOfferAction` para no duplicar lógica en dos controllers).
- **Registro automático (decisión cerrada):** cada scan produce **siempre** una
  `ride_offer` con `status=received`, aunque falte la tarifa o la plataforma. No hay paso de
  confirmación del conductor. Si el estado del scan es `review_required`/`failed`, la oferta
  se registra igualmente con los campos disponibles, `ocr_confidence` baja y `error_code` en
  `raw_payload`, para que cuente en el funnel y quede auditable.
  - `offered_fare` es `NOT NULL` en la tabla: se acepta `0.00` cuando no se pudo leer, y
    `raw_payload['ocr']['missing_fields']` documenta qué faltó. Se prefiere un registro
    incompleto honesto a perder la oferta del funnel.
  - `platform` desconocida → `custom`; `service_type` desconocido → `null` +
    `service_type_raw` + `warnings: ["service_type_unrecognized"]` (nunca se inventa).
  - El cálculo server-side devuelve `null` (sin veredicto) si falta vehículo/precio, igual
    que hoy; el móvil ya mostró su veredicto local. 

### 4.2 Nuevo modelo `ride_offer_scans`

| Columna         | Tipo                    | Uso                                                                         |
| :-------------- | :---------------------- | :-------------------------------------------------------------------------- |
| `id`            | bigint                  |                                                                             |
| `client_uuid`   | uuid                    | Idempotencia de la captura                                                  |
| `user_id`       | FK users                | Propiedad (cascade)                                                         |
| `ride_offer_id` | FK ride_offers nullable | Enlace al resultado                                                         |
| `image_path`    | string                  | Ruta en `local`                                                             |
| `image_hash`    | char(64)                | SHA-256 para dedupe                                                         |
| `platform`      | string                  | Detectada o declarada                                                       |
| `status`        | string                  | `pending`, `processing`, `parsed`, `review_required`, `failed`, `duplicate` |
| `received_at`   | timestamp               | Momento local de la captura (lo envía el móvil).                            |
| `provider`      | string                  | `tesseract` / `azure-read`                                                  |
| `ocr_text`      | text                    | Texto normalizado (sin la zona del mapa)                                    |
| `ocr_tokens`    | json                    | Token×caja de ambos `psm` (para depurar y mostrar)                          |
| `extracted`     | json                    | Resultado estructurado + confianza por campo                                |
| `confidence`    | decimal(5,4)            | Media ponderada por campo crítico                                           |
| `error_code`    | string                  | `unreadable`, `unsupported_platform`, `missing_fare`, …                     |
| `client_extraction` | json nullable       | Extracción hecha por el dispositivo (reconciliación).                       |
| `reconciliation` | string nullable        | `confirmed`, `discrepancy`, `client_only`, `server_only`.                   |
| `expires_at`    | timestamp               | Retención de imagen (p. ej. 30 días)                                        |
| `timestamps`    |                         |                                                                             |

Migración **nueva y reversible**; índice `(user_id, image_hash, created_at)` y
`unique(user_id, client_uuid)`. Las imágenes se borran por un job de retención; **nunca** se
exponen en respuestas (regla de API: no OCR source images).

> **Capturas sin fecha:** verificado en las fixtures: la barra de estado solo muestra la
> hora (`9:27`, `9:26`), no la fecha. Por eso la deduplicación usa `image_hash` + ventana
> de `received_at` reportada por el móvil; **nunca** se deduce la fecha del pixel del reloj.

### 4.3 Ajustes a `ride_offers` (migración nueva)

- `ocr_confidence_by_field` **json nullable** (confianza por campo; `ocr_confidence` se
  mantiene como media, retrocompatible).
- `service_type_raw` **string(64) nullable** (texto tal cual aparecía en pantalla).
- `ride_offer_scan_id` **FK nullable** (trazabilidad captura → oferta).
- Catálogo/índice: `index(platform, service_type)`.
- **Sin borrar ni renombrar columnas.**

---

## 5. Pipelina de extracción (detalle)

### 5.1 Preprocesamiento (`PreprocessRideOfferImageAction`)

1. Corregir orientación con `osd` (si está disponible).
2. Detectar el panel de la oferta por color (fondo casi blanco) y recortarlo; si no se
   detecta, usar la mitad inferior (Uber) o el panel de tarjeta (DiDi).
3. Escalar ×2–3 con `Imagick` si `Imagick` está disponible; si no, `GD`.
4. Generar dos variantes: original en color y escala de grises con umbral adaptativo.
5. Salida: rutas/bytes de las variantes + metadatos (`crop_rect`, `scale`).

> Nota: `Process::` de Laravel se usa para invocar `tesseract` (`Process::fake()` en tests).
> Requiere el binario y `tessdata` (`spa`, `eng`) en el servidor; se documenta en
> `docs/TESTING.md`/deployment y el proveedor Azure queda como _fallback_ configurable.

### 5.2 Reconocimiento

- `tesseract --oem 1 --psm 6 -l spa+eng` sobre la variante del panel → **texto principal**.
- `tesseract --oem 1 --psm 11 -l spa+eng` sobre la imagen completa → **etiquetas** (`Exclusivo`,
  `Viaje disponible`, `Rechazo permitido`).
- Salida TSV (`stdout tsv`) → tokens con `left/top/width/height/conf`, para filtrar por
  posición y calcular confianza por campo.
- `azure-read` (solo si `RIDE_OFFER_OCR_PROVIDER=azure-read`) devuelve `readResults` con `boundingBox`;
  se normaliza al mismo `OcrToken[]`.

### 5.3 Parsing por plataforma (`ExtractRideOfferDataAction` + estrategias)

Una estrategia por plataforma (`UberOfferParser`, `DiDiOfferParser`, `InDriveOfferParser`),
implementando `RideOfferParser`; se selecciona por etiqueta de marca y por forma del texto.
Mantenerlas como clases pequeñas en `app/Services/Offers/` (arquitectura vigente).

**Campos comunes y reglas (basadas en las fixtures reales):**

| Campo                                                      | Uber                                                                   | DiDi                                                           |
| :--------------------------------------------------------- | :--------------------------------------------------------------------- | :------------------------------------------------------------- |
| `offered_fare`                                             | primer importe `$X.XX` en el panel                                     | `$1.56` (tarifa fija) o importe tras “Set your fare”           |
| `surge_amount`                                             | `+$0.36 por inicio de viaje prioritario`                               | recargo explícito si aparece                                   |
| `estimated_pickup_duration_minutes` / `pickup_distance_km` | `11 min (6.3 km)`                                                      | `7min (3,2km)` — **coma decimal**                              |
| `estimated_duration_minutes` / `estimated_distance_km`     | `Viaje: 17 min (7.6 km)`                                               | `9min (5.4km)`                                                 |
| `pickup_address`                                           | línea siguiente al bloque de recogida                                  | `17 de Septiembre, 1335, Llano Chico`                          |
| `destination_address`                                      | línea antes de `Viaje:`                                                | `Pizzería El Tablón, San Isidro del Inca`                      |
| `rider_rating` / `rider_trip_count`                        | `4.91 (377)` — **solo con posición en panel**                          | `4.78 · 41 viajes`                                             |
| `rider_verified`                                           | “Identidad digital verificada”                                         | icono/etiqueta equivalente                                     |
| `payment_method`                                           | no visible                                                             | `Pago en efectivo` → `cash`                                    |
| `service_type`                                             | etiqueta/servicio de la oferta; si no: `null` + warning (no se inventa) | `Express Nuevo` → `express`; `Set your fare` → `set_your_fare` |
| `availability_type`                                        | `Exclusivo` → `exclusive`; `Viaje disponible` → `available_trip`       | exclusiva vs. lista                                            |
| `expires_at`                                               | contador visible → `received_at + N s`                                 | ídem                                                           |
| `platform`                                                 | `uber`                                                                 | `didi`                                                         |

**Reglas anti-ruido:**

1. Descartar tokens fuera del `crop_rect` del panel y tokens cuya `x` sea < 15 % del ancho
   (columna del mapa), salvo que pertenezcan a una línea de dirección ya anclada.
2. Reconstruir líneas agrupando tokens por `top` (± 8 px) y ordenando por `left`.
3. Los patrones de rating exigen `^[0-4]\.\d{2}$` y un conteo entre paréntesis **en la
   misma línea del panel**, para evitar el falso positivo `Ak 491 (377)`.
4. Normalizar decimales por idioma: si la plataforma es `didi` y el patrón es `\d+,\d+`,
   convertir a punto; nunca asumir separador de miles sin verificar el patrón.
5. Fallback de distancias: si solo hay una pareja `(min, km)`, se asume **viaje** cuando va
   precedida de `Viaje:` o cuando el panel tiene > 1; si no, **recogida**. Esa ambigüedad
   se marca con confianza baja y nunca invalida la oferta.
6. Si falta `offered_fare` o no hay plataforma reconocible → `review_required`, con
   `error_code` y `extracted` parcial; **la oferta se registra igualmente** (decisión
   cerrada: siempre se registra), con `offered_fare = 0.00` y `missing_fields`.
7. **No extraer la barra de estado** (hora, batería, WhatsApp): el reloj no tiene fecha y
   nunca debe alimentar `received_at`, `expires_at` ni la deduplicación.

### 5.4 Contrato de respuesta (`RideOfferOcrResource`)

`POST /ride-offers/ocr` (multipart: `image` + campos) o `application/json` con
`image_base64` → `202`:

```json
{
    "image": "<multipart o base64>",
    "client_uuid": "7b1c…",
    "received_at": "2026-11-13T09:27:14-05:00",
    "platform_hint": "uber",
    "vehicle_id": 12,
    "client_extraction": {
        "platform": "uber",
        "service_type": "uberx_priority",
        "offered_fare": "3.45",
        "surge_amount": "0.36",
        "estimated_distance_km": "7.600",
        "pickup_distance_km": "6.300",
        "client_verdict": "accept",
        "client_net_profit_estimate": "1.12",
        "engine": "ml_kit_text_v2",
        "confidence": { "offered_fare": 0.97, "estimated_distance_km": 0.9 }
    }
}
```

Respuesta `202`:

```json
{
    "scan": {
        "client_uuid": "7b1c…",
        "status": "pending",
        "platform": "uber",
        "offer_id": 841,
        "image_retained_until": "2026-12-13T00:00:00Z"
    }
}
```

- `client_uuid` es **obligatorio** y lo genera el móvil: si el reintento de la cola offline
  repite la subida, el endpoint responde `200` con el mismo scan (idempotente).
- `received_at` lo envía el cliente; sin él se usa `now()`. La barra de estado del
  screenshot no se usa como reloj.
- `client_extraction` es **opcional**; la oferta se registra en el `202` (sin esperar OCR),
  con la extracción local como valores iniciales, y el job la completa/reconcilia.

`GET /ride-offers/ocr/{client_uuid}` → `200` (mismo usuario):

```json
{
    "scan": {
        "client_uuid": "…",
        "status": "parsed",
        "platform": "uber",
        "provider": "tesseract",
        "offer": {
            "platform": "uber",
            "service_type": "uberx_priority",
            "service_type_raw": "UberX Priority",
            "availability_type": "exclusive",
            "currency_code": "USD",
            "offered_fare": "3.45",
            "surge_amount": "0.36",
            "estimated_pickup_duration_minutes": 11,
            "pickup_distance_km": "6.300",
            "estimated_duration_minutes": 17,
            "estimated_distance_km": "7.600",
            "pickup_address": "Sta. Elena, Llano Chico",
            "destination_address": null,
            "rider_rating": "4.91",
            "rider_trip_count": 377,
            "rider_verified": true
        },
        "confidence": {
            "offered_fare": 0.98,
            "estimated_distance_km": 0.91,
            "service_type": 0.62
        },
        "warnings": ["service_type_inferred", "destination_address_missing"]
    }
}
```

- **No hay confirmación del conductor (decisión cerrada).** El scan registra la oferta
  automáticamente; `GET` sirve para mostrarla/auditarla, no para aprobarla.
- El móvil no necesita esperar este `GET`: ya tiene su extracción y su veredicto local. El
  `GET` existe para diagnoóstico, historial y clientes web.
- Ambas rutas (`ocr` y `sync`) usan el mismo `UpsertRideOfferAction`.
- Ninguna respuesta expone la imagen, el texto OCR crudo completo ni tokens con cajas en
  producción (solo en `debug` o endpoint admin futuro).
- `warnings` posibles: `service_type_unrecognized`, `missing_fare`, `distance_ambiguous`,
  `client_server_discrepancy`, `client_extraction_used`.

### 5.5 Vínculo con el veredicto

- La tarifa debe ser **del viaje**, no de la recogida: el contrato de cálculo usa
  `estimated_distance_km` como distancia del viaje y `pickup_distance_km` como
  _deadhead_. En las fixtures Uber, la primera línea (`11 min (6.3 km)`) es **recogida** y
  `Viaje:` es el **viaje**; invertirlas cambia el veredicto. Es el error más caro posible.
- Si `vehicle_id` viene en el request del scan, se recomputa al final y se devuelve
  `server_verdict` junto con la oferta (mismo comportamiento que `sync`).

---

## 6. Deduplicación

1. `image_hash = sha256(bytes)` calculado sobre los bytes **originales** (sin recomprimir:
   el móvil haría perder el hash y provocaría duplicados).
2. **Idempotencia primaria:** `unique(user_id, client_uuid)` del scan. El reintento offline
   de la cola del móvil devuelve el mismo scan (`200`), sin reprocesar.
3. **Detección de recaptura:** si existe un scan del mismo usuario + `image_hash` con
   `received_at` dentro de ±120 s y **misma plataforma** → `duplicate`, se enlaza al mismo
   `ride_offer_id` (la oferta no se duplica ni se reagrupa el funnel).
4. `client_uuid`: lo genera el móvil (`uuid v4`) y se conserva entre reintentos. Para
   clientes sin cola offline (web), el servidor genera
   `uuid5(NAMESPACE_URL, "offer|{user}|{platform}|{fare}|{distance}|{received_at_minute}")`
   para que reintentos convergentes no dupliquen.
5. Un `duplicate` **nunca** crea un `received` nuevo en `ride_offer_events`.

---

## 7. Configuración y secretos

`config/ride_offer_ocr.php` (Tesseract es el **predeterminado**, decisión cerrada):

```php
return [
    'provider' => env('RIDE_OFFER_OCR_PROVIDER', 'tesseract'), // tesseract | azure-read
    'tesseract' => [
        'binary' => env('TESSERACT_BINARY', 'tesseract'),
        'languages' => env('TESSERACT_LANGUAGES', 'spa+eng'),
        'psm_primary' => 6,
        'psm_labels' => 11,
        'timeout_seconds' => 20,
    ],
    'preprocess' => [
        'driver' => env('RIDE_OFFER_IMAGE_DRIVER'), // imagick | gd | null
        'scale' => 2.5,
        'crop_strategy' => 'auto', // auto | bottom_half | card
    ],
    'min_confidence' => 0.70,
    'image_retention_days' => 30,
    'reconciliation_tolerance' => [
        'fare_absolute' => '0.01',
        'distance_km_absolute' => '0.05',
    ],
    'max_image_bytes' => 8 * 1024 * 1024,
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
];
```

`default_service_types` se elimina: ya no se inventa `uberx`/`express` cuando no hay
etiqueta (decisión: `service_type` desconocido → `null` + warning).

### 7.1 Lado móvil (`ride-efficiency-mobile`)

Requisitos que el API asume y que deben coordinarse con el repo móvil:

- **Motor OCR en dispositivo:** Android → ML Kit Text Recognition v2 (bundled);
  iOS → Vision (`VNRecognizeTextRequest`) o ML Kit iOS. Sin red. Tesseract nativo solo si
  se necesita control fino del `psm`; **primera opción ML Kit** por latencia y calidad en
  texto de UI.
- **Cálculo local:** npm `gig-economics` + `GET /profitability/profile` cacheado por
  `calculation_version` (contrato v1 ya existe). El veredicto se muestra **antes** de
  cualquier llamada de red.
- **Cola offline:** reintentos exponenciales con el mismo `client_uuid`; la subida usa
  `POST /ride-offers/ocr` y tolera `200` (idempotente) y `202`.
- **Reglas compartidas:** los parsers móvil y servidor deben cubrir los **mismos casos de
  fixture**. Los casos de aceptación (`shared/profitability-fixtures/v1/cases.json` y
  `tests/Fixtures/offers/*.txt`) se replican en el repo móvil para evitar divergencia.
- **Privacidad:** el móvil recorta el panel antes de subir (no envía la barra de estado ni
  el mapa completo); opcionalmente envía solo la región del panel.

`.env.example` y despliegue: requiere binario `tesseract` + `tessdata` (`spa`, `eng`, `osd`)
y extensiones `gd`/`imagick`. Si faltan, el job marca `error_code=provider_unavailable`; la
respuesta nunca es `500` y la oferta **ya está registrada** con la extracción local del móvil
(o como `review_required` si no la hubo).

---

## 8. Tareas (orden de implementación)

Sizes: S / M / L. Todas con test propio.

| #            | Tarea                                                                                                                                        | Size | Depende |
| :----------- | :------------------------------------------------------------------------------------------------------------------------------------------- | :--- | :------ |
| OFFER-OCR-01 ✅ | Catálogo `config/ride_offer_platforms.php` + `ServiceTypeCatalog` (normalización, alias ES/EN) y `GET /ride-offers/catalog` para mobile.       | M    | —       |
| OFFER-OCR-02 ✅ | Migración `ride_offer_scans` (+ `client_extraction`, `reconciliation`) y cambios aditivos en `ride_offers` (reversible).                     | M    | —       |
| OFFER-OCR-03 | Contrato `TextRecognitionProvider` + `TesseractTextRecognitionProvider` (via `Process`, TSV→tokens) + manager (**por defecto**).              | M    | —       |
| OFFER-OCR-04 | `AzureReadTextRecognitionProvider` (prebuilt-read) como **fallback opcional**; normalización de `boundingBox`.                                | M    | 03      |
| OFFER-OCR-05 | `PreprocessRideOfferImageAction` (crop por color de panel, escala, dos variantes, exclusión de la barra de estado).                           | M    | —       |
| OFFER-OCR-06 | `RideOfferParser` + `UberOfferParser` (priority, exclusivo, viaje disponible, surge, rating con posición).                                   | L    | 01, 05  |
| OFFER-OCR-07 | `DiDiOfferParser` (Express, Express Nuevo, Set your fare, efectivo, coma decimal, lista).                                                    | L    | 01, 05  |
| OFFER-OCR-08 | `InDriveOfferParser` (scaffold + tests de contrato, sin lógica de producción todavía).                                                       | S    | 01      |
| OFFER-OCR-09 | `ExtractRideOfferDataAction`: selección de estrategia, confianza por campo, warnings (sin inventar `service_type`).                           | M    | 06, 07  |
| OFFER-OCR-10 | `ProcessRideOfferOcrJob`: dedupe, reconciliación con `client_extraction`, registro **siempre**.                                              | M    | 02, 09  |
| OFFER-OCR-11 | Extraer `UpsertRideOfferAction` de `RideOfferController::sync` y reutilizarlo (sin cambiar el contrato de `sync`).                          | M    | 10      |
| OFFER-OCR-12 | `POST /ride-offers/ocr` (multipart/base64, idempotente por `client_uuid`) + `GET /ride-offers/ocr/{clientUuid}` + ownership + throttling.    | M    | 10, 11  |
| OFFER-OCR-13 | Registro inmediato de la oferta en el `202` (con `client_extraction` o placeholder) y enriquecimiento posterior por el job.                   | M    | 12      |
| OFFER-OCR-14 | Retención de imágenes (`PurgeRideOfferScanImagesJob`, 30 días) y borrado en `delete` del usuario.                                           | S    | 02      |
| OFFER-OCR-15 | Validación de `platform`/`service_type`/`availability_type` contra el catálogo en `SyncRideOffersRequest` (aditivo, tolerante).              | S    | 01      |
| OFFER-OCR-16 | Fixtures: `tests/Fixtures/offers/*.txt` (texto OCR sintético por variante) y anonimizar las `oferta_*.jpeg` reales antes de commitear.        | S    | —       |
| OFFER-OCR-17 | Harness `php artisan ride-offers:ocr-fixtures` (local) que corre la pipeline contra fixtures y reporta aciertos por campo.                   | M    | 09      |
| OFFER-OCR-18 | **Mobile (repo `ride-efficiency-mobile`):** OCR nativo (ML Kit/Vision) + parsers + cálculo local + cola offline de subida + veredicto sin red. | L    | 01, 16  |
| OFFER-OCR-19 | Paridad móvil ↔ servidor sobre los mismos fixtures de texto y `profitability-fixtures/v1/cases.json`.                                         | M    | 17, 18  |
| OFFER-OCR-20 | Documentación: README (endpoints nuevos), `IMPLEMENTATION_PLAN.md` (API-OFFER-22+) y contrato compartido con mobile.                          | S    | 12      |
| OFFER-OCR-21 | Prueba en dispositivo real: 20 capturas por plataforma y métrica de precisión por campo; ajuste de umbrales.                                 | M    | 17, 19  |

**Ruta crítica (API):** 01 → 05 → 06/07 → 09 → 10 → 11/12 → 13.

**Ruta crítica (móvil):** 01 (catálogo) → 16 (fixtures) → 18 → 19 → 21.

> La extracción y el cálculo en mobile (18) **no dependen** de la cola del API: puede
> empezar en paralelo con 02–13 usando el catálogo (01) y los fixtures de texto (16).

---

## 9. Estrategia de pruebas

- **Unit/Fixture (offline):** parsers alimentados con texto OCR sintético (Uber priority,
  Uber available, DiDi exclusive, DiDi list, DiDi set-your-fare) y aserciones por campo.
  Incluir casos negativos: `Ak 491 (377)` no debe ser rating; `3,2km` = 3.2; `All min` no
  debe parsear como duración.
- **Fake de `Process`:** `Process::fake()` con salidas TSV reales (fixtures) para
  `ProcessRideOfferOcrJob`; `Storage::fake('local')`; `Queue::fake()` en el endpoint.
- **Feature:** `202` + propiedad por usuario, `403/404` cruzado, validación (`422`) de
  MIME/tamaño/plataforma, `duplicate`, reintento idempotente con el mismo `client_uuid`,
  no exposición de `image_path`/`ocr_text` en respuestas.
- **Registro automático:** un scan con `client_extraction` y uno sin ella **crean** una
  `ride_offer` en el `202`; un scan sin tarifa crea la oferta con `offered_fare=0.00` y
  `missing_fare` (no se pierde el funnel).
- **Reconciliación:** coincidencia → `confirmed`; divergencia > tolerancia → `discrepancy`
  y gana el servidor; OCR ilegible + extracción local válida → `client_only`.
- **Contrato:** forma del JSON de `GET /ride-offers/ocr/{uuid}` en cada estado.
- **Aceptación de precisión:** ≥ 95 % en `offered_fare` y `estimated_distance_km`;
  ≥ 90 % en dirección de recogida; `service_type` **nunca inventado** (si no es claro →
  `null` + warning). Medido con OFFER-OCR-17/21.
- **Paridad (OFFER-OCR-19):** mismos fixtures de texto y mismas `cases.json` en mobile y
  API; el veredicto local y el `server_verdict` deben coincidir para el mismo payload.
- `composer test` (Pint + PHPStan nivel 7 + Pest) antes de cerrar.

---

## 10. Riesgos y mitigaciones

| Riesgo                                                        | Impacto                           | Mitigación                                                                                          |
| :------------------------------------------------------------ | :-------------------------------- | :-------------------------------------------------------------------------------------------------- |
| Confundir recogida con viaje                                  | Veredicto totalmente erróneo      | Regla `Viaje:` + separación explícita y test de regresión                                           |
| Relleno semitransparente del panel (Uber) deja texto del mapa | Falsos positivos (`Ak 491 (377)`) | Crop por color + filtro por `x` + validación de formato                                             |
| `spa`/`eng` resuelven mal tipografía pequeña                  | Etiquetas frágiles (`Exclusivo`)  | Doble `psm`, inferencia desde contexto y `service_type` opcional                                    |
| Binario Tesseract ausente en producción                       | Job falla en masa                 | Preflight check + `provider_unavailable` + fallback Azure Read                                      |
| Capturas duplicadas                                           | Métricas infladas                 | `image_hash` + ventana + `client_uuid` determinista                                                 |
| PII en capturas (nombres, direcciones, teléfonos en la barra) | Cumplimiento                      | Recorte estricto del panel, no persistir texto de la barra de estado, retención de imagen y borrado |
| Cambios de UI de Uber/DiDi                                    | Parsers obsoletos                 | Catálogo + fixtures + harness + `raw_payload` para diagnosis                                        |
| Coste Azure                                                   | Billing                           | Tesseract por defecto; Azure solo fallback configurable                                             |
| Mobile sin red o con API caído                                | Sin veredicto                     | Extracción + cálculo locales; la subida se reintenta desde la cola offline                          |
| Batería/CPU en el dispositivo                                 | Mala experiencia                  | ML Kit, procesar solo el panel recortado y sin recodificar la imagen que se sube                    |
| Divergencia móvil ↔ servidor                                  | Métricas inconsistentes           | Reconciliación explícita por campo + paridad de fixtures en ambos repos                             |
| Duplicados por reintento offline                              | Funnel inflado                    | `client_uuid` estable + `200` idempotente + `image_hash` + ventana ±120 s                            |

---

## 11. Decisiones cerradas y pendientes

### Cerradas

1. **Tesseract por defecto** en el servidor; Azure Read queda como fallback opcional.
2. **Sin confirmación del conductor:** toda oferta detectada se registra automáticamente;
   un scan de baja confianza registra la oferta con los campos disponibles y warning.
3. **Mobile es el camino crítico:** extracción nativa (ML Kit/Vision) + cálculo local con
   `gig-economics`; la imagen se sube después y el API procesa en segundo plano.
4. **`service_type` no se inventa** cuando no hay etiqueta
   (`null` + `service_type_raw` + warning).

### Pendientes (bajo impacto, decisiones sugeridas)

1. **Catálogo por país:** sugerencia → catálogo global con alias; `country_code` solo para
   habilitar/ocultar tipos por mercado si aparece un conflicto real.
2. **Fixtures reales:** las `oferta_*.jpeg` tienen direcciones y nombres reales de Quito;
   anonimizarlas o reemplazarlas antes de commitearlas (regla de datos de prueba), y usar
   los `*.txt` sintéticos como fuente de verdad de los tests.
3. **Retención de la imagen:** sugerencia 30 días, configurable; se conserva la extracción
   y se borra el archivo.
