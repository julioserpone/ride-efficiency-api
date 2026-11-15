# Plan — Offer OCR (Uber / DiDi / inDrive)

> **Status:** proposal v2 (plan mode, no code changes applied). Closed decisions:
> Tesseract by default, automatic registration without confirmation, and **native
> extraction on mobile**.
> **Scope:** Laravel 13 API (`/api/v1`) **and** `ride-efficiency-mobile` (on-device
> extraction + local calculation). The API is never on the critical path of the
> driver's response.
> **Fixtures:** `tests/oferta_uber_exclusiva.jpeg`, `tests/oferta_uber_viaje_disponible.jpeg`,
> `tests/oferta_didi.jpeg`, `tests/oferta_didi_exclusiva.jpeg`, `tests/oferta_lista_didi.jpeg`.

---

## 1. Review of the current offers functionality

### 1.1 What already exists and works

| Layer   | Element                                                                                                                          | Notes                                                                                                                                                                                                                                                                                          |
| :------ | :-------------------------------------------------------------------------------------------------------------------------------- | :--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Data    | `ride_offers`                                                                                                                     | 45 columns: platform, `service_type`, `availability_type`, fare, surge, tip, toll, distances and durations (trip and pickup), addresses, rider rating/trips/verification, `expires_at`, `source`, `ocr_confidence`, `raw_payload`, statuses and client/server verdicts. |
| Data    | `ride_offer_events`                                                                                                               | Append-only funnel with deduplication on `(event_type, occurred_at)`.                                                                                                                                                                                                                          |
| Data    | `daily_offer_metrics`                                                                                                             | Daily per-user aggregate (`AggregateDailyOfferMetricsJob`).                                                                                                                                                                                                                                 |
| API     | `POST /api/v1/ride-offers/sync`                                                                                                   | Batch upsert (`client_uuid` idempotent), partial success, events, server-side recompute.                                                                                                                                                                                                    |
| API     | `POST /api/v1/ride-offers/evaluate`                                                                                               | Stateless fallback.                                                                                                                                                                                                                                                                     |
| API     | `GET /ride-offers`, `GET /ride-offers/{id}`, `PATCH /ride-offers/{id}`                                                            | Filters, pagination, state machine (`received → evaluated → accepted/rejected/expired`, `accepted → completed/cancelled`).                                                                                                                                                                |
| API     | `GET /ride-offers/summary/daily`                                                                                                  | Funnel and averages.                                                                                                                                                                                                                                                                            |
| Jobs    | `ExpireRideOffersJob` (every minute), `AggregateDailyOfferMetricsJob` (daily)                                                     | Registered in `routes/console.php`.                                                                                                                                                                                                                                                           |
| Calc    | `RecomputeRideOfferProfitabilityAction`                                                                                           | Uses `julioserpone/gig-economics`; requires vehicle + energy price; `Platform::tryFrom()` already supports `uber`, `didi`, `indrive`, `lyft`, `custom`.                                                                                                                                         |
| OCR     | `ReceiptAnalysisProvider` + `ReceiptAnalysisProviderManager` + `AzureDocumentIntelligenceClient`                                  | `prebuilt-receipt` only; designed for energy invoices.                                                                                                                                                                                                                                                |
| Tests   | `RideOfferSyncTest`, `RideOfferApiTest`, `RideOfferStateMachineTest`, `RideOfferLinkAndAggregateTest`, `OcrProcessingServiceTest` | Solid lifecycle coverage; **no offer OCR**.                                                                                                                                                                                                                                    |

### 1.2 Concrete gaps (relevant to this task)

1. **No image input for offers.** `source`, `ocr_confidence` and `raw_payload` exist, but there
   is no endpoint or job that produces them (neither from mobile nor as a server fallback).
2. **No type taxonomy.** `platform` and `service_type` are free `string(32)` with no catalogue
   or normalisation (`UberX`, `uber_x`, `Uber X`), and no defined values for
   `availability_type` (exclusive / priority / available trip / set your fare).
3. **No plain-text OCR provider.** The provider contract returns `prebuilt-receipt` JSON;
   Tesseract returns text/TSV. Adapting the provider is part of the plan.
4. **`sync` does not deduplicate repeated captures.** Two screenshots of the same offer with
   different `client_uuid`s create duplicates.
5. **`ocr_confidence` is a scalar.** Per-offer confidence is per field (fare, distances, type),
   not global.
6. **`expires_at` is not extracted.** Offers expire in seconds; today it comes from the client.

---

## 2. Empirical validation with Tesseract (done in this review)

Local environment: Tesseract **5.5.3** (eng, spa, osd), PHP 8.5 with `gd`, `imagick`, `bcmath`.

| Image                                       | `--psm 6` (block)                                                                                                                                                                     | `--psm 11` (sparse text)    |
| :------------------------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------- |
| `oferta_uber_exclusiva.jpeg`                 | `$3.45`, `Identidad digital verificada`, `4.91 (377)`, `+$0.36 por inicio de viaje prioritario`, `11 min (6.3 km)`, `Sta. Elena, Llano Chico`, `Viaje: 17 min (7.6 km)`                | also detects **`Exclusivo`** |
| `oferta_uber_viaje_disponible.jpeg`          | `$2.63`, `Identidad digital verificada`, `4.86 (168)`, `13 min (5.5 km)`, `Julio Yanez, Comite del Pueblo`, `Viaje: 14 min (5.8 km)`, `Viaje disponible`                               | same                          |
| `oferta_didi_exclusiva.jpeg`                 | `Pago en efectivo`, `$1.56`, `4.78 · 41 viajes`, `7min (3,2km)`, `17 de Septiembre, 1335, Llano Chico`, `9min (5.4km)`, `Pizzería El Tablón, San Isidro del Inca`, `Rechazo permitido` | no extra labels                |
| `oferta_didi.jpeg`                           | `Express Nuevo`, `4.88 · 414 viajes`, `(4 min 1.3 km) 23 de Abril, Calderon`, `(17 min 8.9 km) Avenida del Maestro & Melchor Toaza, Kennedy`                                           | same                          |
| `oferta_lista_didi.jpeg` (list, no offer)    | `Centro de viajes`, `Express Nuevo`, `No hay más solicitudes`                                                                                                                          | use `psm 4`                   |

**Observed conclusions**

- **It works.** Business text reads with good quality; the noise is the **map**.
- **Noise is spatial, not semantic:** stray letters from street names on the left. Filtered
  by position (start column / width), not by content.
- **`psm 6` vs `psm 11`:** `psm 6` reproduces the panel reading order; `psm 11` recovers
  Uber's isolated `Exclusivo` label that `psm 6` misses. The plan uses **both** (`psm 6`
  for parsing, `psm 11` as label reinforcement).
- **Colours help:** the offer panel is almost white (`≈247–255`) while the map is light
  blue (`≈244,246,255` with a pattern), which lets us crop the panel region.
- **Small type is lost in the full image.** Cropping + scaling ×2–3 fixes `11 min` (which
  came back as `All min`), but **`Exclusivo`** and signs such as `%` remain fragile.
  Service type **must not depend on OCR**: detect it from the label and confirm from the
  platform context/colour.
- **DiDi uses decimal commas** (`3,2km`) and `·` separators; Uber uses `·` in the rating
  and `(6.3 km)`.
- **False-positive risk:** in `oferta_uber_exclusiva` a map label `Ak 491 (377)` (POI)
  matches the **rating pattern** (`4.91 (377)`). A regex-only rating parser could confuse
  them. Require position and coherence (rating ≤ 5.00 + parenthesised count + located in
  the panel).

---

## 3. Platform taxonomy and service types

The contract must be **stable** even when OCR fails. Proposed versioned catalogue in
`config/ride_offer_platforms.php` (not in the DB: it is domain configuration, not user
data) exposed by `GET /api/v1/ride-offers/catalog` so that **mobile** uses exactly the
same canonical codes as the server (single source of truth, no duplicated list in
TypeScript).

### 3.1 Platforms (`platform`, values already accepted by `Platform`)

`uber`, `didi`, `indrive`, `lyft` (reserved), `custom`.

### 3.2 Uber (`service_type`)

UberX, **UberX Priority** ("Uber Priority"), **UberX Share** ("Uber Juntos"), Uber Comfort,
Uber Comfort Electric, Uber Black, Uber Black SUV, UberXL, Uber Pet, Uber Electric, Uber
Van, Uber Taxi, Uber Reserve, Uber Connect, Uber Intercity/Intercity (Interurbano),
Uber Moto (2 or 3 wheels), Uber Auto (rickshaw). Reference:
[Uber — Ride options](https://www.uber.com/us/en/ride/ride-options/).

Canonical `snake_case` normalisation: `uberx`, `uberx_priority`, `uberx_share`,
`comfort`, `comfort_electric`, `black`, `black_suv`, `xl`, `pet`, `electric`, `van`,
`taxi`, `reserve`, `connect`, `intercity`, `moto`, `auto`.

### 3.3 DiDi (`service_type`)

`express` (Express / Express Nuevo / DiDi Express), `taxi` (DiDi Taxi),
`set_your_fare` (Set your fare — driver proposes the fare), `moto`, `comfort`,
`priority`/`scheduled`, `list` (**"Centro de viajes" screen, not an active offer**).
Reference: [DiDi Pasajero Ecuador](https://web.didiglobal.com/ec/pasajero/).
For countries with a different catalogue (MX/BR/CO), the catalogue is optionally keyed by
`country_code`; an unknown value falls back to `custom:<normalised text>` and is logged
as a label.

### 3.4 inDrive (future)

`city` (negotiated fare, driver proposes), `intercity`, `courier`. No logic now: only
enters the catalogue and the contract.

### 3.5 Assignment type (`availability_type`)

Currently a free `string(32)`. Proposed values:

| Value            | On-screen signal                                                          |
| :--------------- | :------------------------------------------------------------------------ |
| `exclusive`      | "Exclusivo" (Uber), "Exclusiva", no counter of other drivers (DiDi)       |
| `priority`       | "+$0.36 por inicio de viaje prioritario", "Priority"                      |
| `available_trip` | "Viaje disponible" (Uber), list of requests without assignment             |
| `set_your_fare`  | "Set your fare / Establece tu tarifa"                                     |
| `metered`        | DiDi Taxi (metered/estimated fare, not fixed)                             |
| `unknown`        | Not detectable                                                            |

---

## 4. Proposed architecture

### 4.0 Mobile-first flow (critical path, on the device)

Requirement: **mobile never waits for the API** to show the verdict. The capture is
processed natively and the image is sent afterwards, in the background.

```
mobile: offer detected (accessibility / notification / screenshot)
   │
   ├─1─► RideOfferOcrService (native, ON-DEVICE)
   │       ├─ on-device preprocessing (panel crop by colour + scaling)
   │       ├─ local OCR: ML Kit Text Recognition (Android/iOS) or native Tesseract
   │       └─ per-platform parser (same rule spec as the server)
   │
   ├─2─► CalculateOfferProfitability (npm `gig-economics`, local, < 100 ms)
   │       └─ verdict shown to the driver WITHOUT network
   │
   └─3─► local persistent queue (SQLite/AsyncStorage) → deferred upload
           POST /api/v1/ride-offers/ocr  (image + local result)   ── 202
             │
             ▼
     StoreRideOfferOcrRequest ── idempotent on `client_uuid` + `image_hash`
             │
             ▼
     RideOfferOcrScan (pending)
        ├─ UpsertRideOfferAction (immediate on 202: client_extraction or placeholder)
        └─ ProcessRideOfferOcrJob (queued, tesseract)
             ├─ PreprocessRideOfferImageAction   (GD/Imagick: crop panel, scale ×2.5)
             ├─ TesseractTextRecognitionProvider (psm 6 + psm 11 → tokens)
             ├─ ExtractRideOfferDataAction       (per-platform rules, per-field confidence)
             ├─ Reconciliation against local result (see §4.4)
             └─ Enrich the SAME offer (never duplicated; no confirmation)
                │
                ▼
        RecomputeRideOfferProfitabilityAction (server verdict)
```

**Availability rule:** the verdict shown to the driver **never** depends on the network.
With the API down, mobile keeps extracting and calculating; the queue retries later.

### 4.4 Mobile ↔ server reconciliation

| Case | Rule |
| :--- | :--- |
| `POST /ocr` payload carries `client_extraction` | Persisted as-is in `ride_offer_scans.client_extraction`; the server **does not** trust it for calculation. |
| Server OCR with `confidence ≥ min_confidence` | Compare `offered_fare`, `estimated_distance_km`, `platform`. If they match (tolerance ±0.01 / ±0.05 km) → `confirmed`. |
| Divergence on critical fields | `discrepancy`; server extraction is authoritative and `discrepancies` (mobile vs server) are logged to measure both engines' accuracy. |
| Server OCR unreadable | Use the local extraction (already validated on device) and mark `source=ocr_mobile`; the offer is still registered. |
| Scan without local result | Pure server flow (web clients / fallback). |
| Scan without `client_extraction` (API was down at capture) | Registered with a placeholder on 202 and completed by the job; if OCR fails it stays `review_required` **with the offer registered**. |

The goal of reconciliation is not to block: it **repairs** data and measures which engine
is more accurate per platform and UI version.

### 4.1 Reuse of existing pieces (do not reinvent)

- **A new text-provider contract**: `TextRecognitionProvider::recognize(bytes, mime, options): OcrTextResult`
  with `tesseract` and `azure-read` (prebuilt-read) implementations. The
  `ReceiptAnalysisProvider`/`ReceiptDataExtractor` contract **is not touched** (still
  invoices). A `TextRecognitionProviderManager` follows the same pattern as
  `ReceiptAnalysisProviderManager`.
- **Persistence:** the image is stored in `local` with the same pattern as
  `EnergyInvoiceController` (`ride_offers/{user_id}/{uuid}.jpg`), validating MIME and size.
- **`sync` and the state machine are reused:** OCR produces a payload compatible with
  `SyncRideOffersRequest` and calls the same upsert path (extracted to an
  `UpsertRideOfferAction` to avoid duplicating logic across two controllers).
- **Automatic registration (closed decision):** every scan **always** produces a
  `ride_offer` with `status=received`, even if the fare or platform is missing. There is
  no driver confirmation step. If the scan status is `review_required`/`failed`, the offer
  is still registered with the available fields, low `ocr_confidence` and `error_code` in
  `raw_payload`, so it counts in the funnel and stays auditable.
  - `offered_fare` is `NOT NULL` in the table: `0.00` is accepted when it could not be
    read, and `raw_payload['ocr']['missing_fields']` documents what was missing. An
    incomplete but honest record is preferred to losing the offer from the funnel.
  - Unknown platform → `custom`; unknown `service_type` → `null` + `service_type_raw` +
    `warnings: ["service_type_unrecognized"]` (never invented).
  - Server-side calculation returns `null` (no verdict) when vehicle/price are missing,
    same as today; mobile already showed its local verdict.

### 4.2 New model `ride_offer_scans`

| Column          | Type                    | Use                                                                         |
| :-------------- | :---------------------- | :-------------------------------------------------------------------------- |
| `id`            | bigint                  |                                                                             |
| `client_uuid`   | uuid                    | Capture idempotency                                                         |
| `user_id`       | FK users                | Ownership (cascade)                                                         |
| `ride_offer_id` | FK ride_offers nullable | Link to the produced offer                                                  |
| `image_path`    | string                  | Path in `local`                                                             |
| `image_hash`    | char(64)                | SHA-256 for dedupe                                                          |
| `platform`      | string                  | Detected or declared                                                        |
| `status`        | string                  | `pending`, `processing`, `parsed`, `review_required`, `failed`, `duplicate` |
| `received_at`   | timestamp               | Local capture moment (sent by mobile).                                      |
| `provider`      | string                  | `tesseract` / `azure-read`                                                  |
| `ocr_text`      | text                    | Normalised text (map zone excluded)                                         |
| `ocr_tokens`    | json                    | Token×box from both `psm` (debug/display)                                   |
| `extracted`     | json                    | Structured result + per-field confidence                                    |
| `confidence`    | decimal(5,4)            | Weighted average over critical fields                                        |
| `error_code`    | string                  | `unreadable`, `unsupported_platform`, `missing_fare`, …                     |
| `client_extraction` | json nullable       | Extraction done on the device (reconciliation).                             |
| `reconciliation` | string nullable        | `confirmed`, `discrepancy`, `client_only`, `server_only`.                   |
| `expires_at`    | timestamp               | Image retention (e.g. 30 days)                                              |
| `timestamps`    |                         |                                                                             |

New **reversible** migration; index `(user_id, image_hash, created_at)` and
`unique(user_id, client_uuid)`. Images are deleted by a retention job; they are **never**
exposed in responses (API rule: no OCR source images).

> **Captures without a date:** verified against the fixtures — the status bar only shows
> the time (`9:27`, `9:26`), not the date. So dedupe uses `image_hash` + the
> `received_at` window reported by mobile; the date is **never** inferred from the clock
> pixels.

### 4.3 Changes to `ride_offers` (new migration)

- `ocr_confidence_by_field` **json nullable** (per-field confidence; `ocr_confidence`
  stays as the average, backwards compatible).
- `service_type_raw` **string(64) nullable** (text exactly as shown on screen).
- `ride_offer_scan_id` **nullable FK** (traceability capture → offer).
- Catalogue/index: `index(platform, service_type)`.
- **No column is dropped or renamed.**

---

## 5. Extraction pipeline (detail)

### 5.1 Preprocessing (`PreprocessRideOfferImageAction`)

1. Correct orientation with `osd` when available.
2. Detect the offer panel by colour (near-white background) and crop it; if not
   detected, use the bottom half (Uber) or the card panel (DiDi).
3. Scale ×2–3 with `Imagick` when available, otherwise `GD`.
4. Produce two variants: original in colour and greyscale with adaptive threshold.
5. Output: variant paths/bytes + metadata (`crop_rect`, `scale`).

> Note: Laravel's `Process::` invokes `tesseract` (`Process::fake()` in tests).
> Requires the binary and `tessdata` (`spa`, `eng`) on the server; documented in
> `docs/TESTING.md`/deployment; Azure remains a configurable **fallback**.

### 5.2 Recognition

- `tesseract --oem 1 --psm 6 -l spa+eng` on the panel variant → **main text**.
- `tesseract --oem 1 --psm 11 -l spa+eng` on the full image → **labels**
  (`Exclusivo`, `Viaje disponible`, `Rechazo permitido`).
- TSV output (`stdout tsv`) → tokens with `left/top/width/height/conf`, filtered by
  position and used to compute per-field confidence.
- `azure-read` (only when `RIDE_OFFER_OCR_PROVIDER=azure-read`) returns `readResults`
  with `boundingBox`; normalised to the same `OcrToken[]`.

### 5.3 Per-platform parsing (`ExtractRideOfferDataAction` + strategies)

One strategy per platform (`UberOfferParser`, `DiDiOfferParser`, `InDriveOfferParser`),
implementing `RideOfferParser`; selected by brand label and text shape. Keep them as
small classes under `app/Services/Offers/` (existing architecture).

**Common fields and rules (based on the real fixtures):**

| Field                                                      | Uber                                                                   | DiDi                                                           |
| :--------------------------------------------------------- | :--------------------------------------------------------------------- | :------------------------------------------------------------- |
| `offered_fare`                                             | first `$X.XX` amount in the panel                                      | `$1.56` (fixed fare) or amount after "Set your fare"           |
| `surge_amount`                                             | `+$0.36 por inicio de viaje prioritario`                               | explicit surcharge when present                                 |
| `estimated_pickup_duration_minutes` / `pickup_distance_km` | `11 min (6.3 km)`                                                      | `7min (3,2km)` — **decimal comma**                             |
| `estimated_duration_minutes` / `estimated_distance_km`     | `Viaje: 17 min (7.6 km)`                                               | `9min (5.4km)`                                                 |
| `pickup_address`                                           | line after the pickup block                                            | `17 de Septiembre, 1335, Llano Chico`                          |
| `destination_address`                                      | line before `Viaje:`                                                   | `Pizzería El Tablón, San Isidro del Inca`                      |
| `rider_rating` / `rider_trip_count`                        | `4.91 (377)` — **panel position only**                                 | `4.78 · 41 viajes`                                             |
| `rider_verified`                                           | "Identidad digital verificada"                                         | equivalent icon/label                                          |
| `payment_method`                                           | not visible                                                            | `Pago en efectivo` → `cash`                                    |
| `service_type`                                             | offer label/service; if none: `null` + warning (never invented)        | `Express Nuevo` → `express`; `Set your fare` → `set_your_fare` |
| `availability_type`                                        | `Exclusivo` → `exclusive`; `Viaje disponible` → `available_trip`       | exclusive vs. list                                             |
| `expires_at`                                               | visible counter → `received_at + N s`                                  | same                                                           |
| `platform`                                                 | `uber`                                                                 | `didi`                                                         |

**Anti-noise rules:**

1. Discard tokens outside the panel `crop_rect` and tokens with `x` < 15 % of width
   (map column), unless they belong to an already anchored address line.
2. Rebuild lines by grouping tokens by `top` (± 8 px) and sorting by `left`.
3. Rating patterns require `^[0-4]\.\d{2}$` and a parenthesised count **on the same
   panel line**, avoiding the `Ak 491 (377)` false positive.
4. Normalise decimals by language: when the platform is `didi` and the pattern is
   `\d+,\d+`, convert to a dot; never assume a thousands separator without verifying
   the pattern.
5. Distance fallback: when only one `(min, km)` pair exists, assume **trip** when
   preceded by `Viaje:` or when the panel has > 1 pair; otherwise **pickup**. That
   ambiguity is flagged with low confidence and never invalidates the offer.
6. If `offered_fare` is missing or the platform is unrecognisable → `review_required`,
   with `error_code` and partial `extracted`; **the offer is still registered** (closed
   decision: always registered), with `offered_fare = 0.00` and `missing_fields`.
7. **Do not extract the status bar** (time, battery, WhatsApp): the clock has no date and
   must never feed `received_at`, `expires_at` or dedupe.

### 5.4 Response contract (`RideOfferOcrResource`)

`POST /ride-offers/ocr` (multipart: `image` + fields) or `application/json` with
`image_base64` → `202`:

```json
{
    "image": "<multipart or base64>",
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

`202` response:

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

- `client_uuid` is **mandatory** and generated by mobile: on an offline-queue retry the
  endpoint responds `200` with the same scan (idempotent).
- `received_at` is sent by the client; without it `now()` is used. The screenshot status
  bar is never used as a clock.
- `client_extraction` is **optional**; the offer is registered on `202` (without waiting
  for OCR), with the local extraction as initial values, and the job completes/reconciles.

`GET /ride-offers/ocr/{client_uuid}` → `200` (same user):

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

- **No driver confirmation (closed decision).** The scan registers the offer
  automatically; `GET` is for display/audit, not approval.
- Mobile does not need to wait for this `GET`: it already has its extraction and local
  verdict. The `GET` exists for diagnostics, history and web clients.
- Both routes (`ocr` and `sync`) share the same `UpsertRideOfferAction`.
- No response exposes the image, the full raw OCR text or the bounding boxes in
  production (only in `debug` or a future admin endpoint).
- Possible `warnings`: `service_type_unrecognized`, `missing_fare`, `distance_ambiguous`,
  `client_server_discrepancy`, `client_extraction_used`.

### 5.5 Link to the verdict

- The fare must be the **trip** fare, not the pickup: the calculation contract uses
  `estimated_distance_km` as trip distance and `pickup_distance_km` as deadhead. In the
  Uber fixtures the first line (`11 min (6.3 km)`) is **pickup** and `Viaje:` is the
  **trip**; swapping them changes the verdict. This is the most expensive possible error.
- If `vehicle_id` is present in the scan request, recompute at the end and return
  `server_verdict` with the offer (same behaviour as `sync`).

---

## 6. Deduplication

1. `image_hash = sha256(bytes)` computed on the **original bytes** (without
   re-encoding: mobile re-encoding would invalidate the hash and create duplicates).
2. **Primary idempotency:** `unique(user_id, client_uuid)` on the scan. An offline-queue
   retry from mobile returns the same scan (`200`) without reprocessing.
3. **Recapture detection:** if a scan exists for the same user + `image_hash` with
   `received_at` within ±120 s and **same platform** → `duplicate`, linked to the same
   `ride_offer_id` (no duplicated offer, no extra funnel entry).
4. `client_uuid`: generated by mobile (`uuid v4`) and kept across retries. For clients
   without an offline queue (web), the server generates
   `uuid5(NAMESPACE_URL, "offer|{user}|{platform}|{fare}|{distance}|{received_at_minute}")`
   so convergent retries do not duplicate.
5. A `duplicate` **never** creates a new `received` event in `ride_offer_events`.

---

## 7. Configuration and secrets

`config/ride_offer_ocr.php` (Tesseract is the **default**, closed decision):

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

`default_service_types` is removed: a service type is no longer invented as
`uberx`/`express` when there is no label (decision: unknown `service_type` → `null` +
warning).

### 7.1 Mobile side (`ride-efficiency-mobile`)

Requirements the API assumes and that must be coordinated with the mobile repo:

- **On-device OCR engine:** Android → ML Kit Text Recognition v2 (bundled);
  iOS → Vision (`VNRecognizeTextRequest`) or ML Kit iOS. No network. Native Tesseract
  only if fine-grained `psm` control is needed; **ML Kit is the first choice** for
  latency and UI-text quality.
- **Local calculation:** npm `gig-economics` + cached `GET /profitability/profile` by
  `calculation_version` (contract v1 already exists). The verdict is shown **before**
  any network call.
- **Offline queue:** exponential retries with the same `client_uuid`; upload uses
  `POST /ride-offers/ocr` and tolerates `200` (idempotent) and `202`.
- **Shared rules:** mobile and server parsers must cover the **same fixture cases**.
  Acceptance cases (`shared/profitability-fixtures/v1/cases.json` and
  `tests/Fixtures/offers/*.txt`) are replicated in the mobile repo to avoid divergence.
- **Privacy:** mobile crops the panel before uploading (no status bar, no full map);
  optionally it sends only the panel region.

`.env.example` and deployment: requires the `tesseract` binary + `tessdata` (`spa`, `eng`,
`osd`) and the `gd`/`imagick` extensions. If they are missing the job sets
`error_code=provider_unavailable`; the response is never `500` and the offer is **already
registered** with the mobile's local extraction (or as `review_required` if there was
none).

---

## 8. Tasks (implementation order)

Sizes: S / M / L. Each task has its own tests.

| #            | Task                                                                                                                                        | Size | Depends |
| :----------- | :------------------------------------------------------------------------------------------------------------------------------------------- | :--- | :------ |
| OFFER-OCR-01 ✅ | Catalogue `config/ride_offer_platforms.php` + `ServiceTypeCatalog` (normalisation, ES/EN aliases) and `GET /ride-offers/catalog` for mobile.  | M    | —       |
| OFFER-OCR-02 ✅ | Migration `ride_offer_scans` (+ `client_extraction`, `reconciliation`) and additive changes to `ride_offers` (reversible).                   | M    | —       |
| OFFER-OCR-03 | Contract `TextRecognitionProvider` + `TesseractTextRecognitionProvider` (via `Process`, TSV→tokens) + manager (**default**).                  | M    | —       |
| OFFER-OCR-04 | `AzureReadTextRecognitionProvider` (prebuilt-read) as an **optional fallback**; `boundingBox` normalisation.                                  | M    | 03      |
| OFFER-OCR-05 | `PreprocessRideOfferImageAction` (panel crop by colour, scaling, two variants, status-bar exclusion).                                         | M    | —       |
| OFFER-OCR-06 | `RideOfferParser` + `UberOfferParser` (priority, exclusive, available trip, surge, rating by position).                                     | L    | 01, 05  |
| OFFER-OCR-07 | `DiDiOfferParser` (Express, Express Nuevo, Set your fare, cash, decimal comma, list).                                                        | L    | 01, 05  |
| OFFER-OCR-08 | `InDriveOfferParser` (scaffold + contract tests, no production logic yet).                                                                   | S    | 01      |
| OFFER-OCR-09 | `ExtractRideOfferDataAction`: strategy selection, per-field confidence, warnings (never invent `service_type`).                               | M    | 06, 07  |
| OFFER-OCR-10 | `ProcessRideOfferOcrJob`: dedupe, reconciliation with `client_extraction`, always registers.                                                 | M    | 02, 09  |
| OFFER-OCR-11 | Extract `UpsertRideOfferAction` from `RideOfferController::sync` and reuse it (no change to the `sync` contract).                            | M    | 10      |
| OFFER-OCR-12 | `POST /ride-offers/ocr` (multipart/base64, idempotent on `client_uuid`) + `GET /ride-offers/ocr/{clientUuid}` + ownership + throttling.       | M    | 10, 11  |
| OFFER-OCR-13 | Immediate offer registration on the `202` (with `client_extraction` or placeholder) and later enrichment by the job.                          | M    | 12      |
| OFFER-OCR-14 | Image retention (`PurgeRideOfferScanImagesJob`, 30 days) and deletion on user delete.                                                       | S    | 02      |
| OFFER-OCR-15 | Validate `platform`/`service_type`/`availability_type` against the catalogue in `SyncRideOffersRequest` (additive, tolerant).                | S    | 01      |
| OFFER-OCR-16 | Fixtures: `tests/Fixtures/offers/*.txt` (synthetic OCR text per variant) and anonymise the real `oferta_*.jpeg` before committing.            | S    | —       |
| OFFER-OCR-17 | Harness `php artisan ride-offers:ocr-fixtures` (local) that runs the pipeline over fixtures and reports per-field accuracy.                   | M    | 09      |
| OFFER-OCR-18 | **Mobile (repo `ride-efficiency-mobile`):** native OCR (ML Kit/Vision) + parsers + local calculation + offline upload queue + verdict without network. | L | 01, 16  |
| OFFER-OCR-19 | Mobile ↔ server parity on the same text fixtures and `profitability-fixtures/v1/cases.json`.                                                  | M    | 17, 18  |
| OFFER-OCR-20 | Documentation: README (new endpoints), `IMPLEMENTATION_PLAN.md` (API-OFFER-22+) and shared contract with mobile.                              | S    | 12      |
| OFFER-OCR-21 | Real-device test: 20 captures per platform and per-field accuracy metric; threshold tuning.                                                  | M    | 17, 19  |

**Critical path (API):** 01 → 05 → 06/07 → 09 → 10 → 11/12 → 13.

**Critical path (mobile):** 01 (catalogue) → 16 (fixtures) → 18 → 19 → 21.

> Mobile extraction and calculation (18) **do not depend** on the API queue: they can
> start in parallel with 02–13 using the catalogue (01) and the text fixtures (16).

---

## 9. Testing strategy

- **Unit/Fixture (offline):** parsers fed with synthetic OCR text (Uber priority,
  Uber available, DiDi exclusive, DiDi list, DiDi set-your-fare) with per-field
  assertions. Negative cases: `Ak 491 (377)` must not be a rating; `3,2km` = 3.2;
  `All min` must not parse as a duration.
- **`Process` fake:** `Process::fake()` with real TSV outputs (fixtures) for
  `ProcessRideOfferOcrJob`; `Storage::fake('local')`; `Queue::fake()` on the endpoint.
- **Feature:** `202` + per-user ownership, cross-user `403/404`, validation (`422`) on
  MIME/size/platform, `duplicate`, idempotent retry with the same `client_uuid`, no
  exposure of `image_path`/`ocr_text` in responses.
- **Automatic registration:** a scan with `client_extraction` and one without **both**
  create a `ride_offer` on `202`; a scan without a fare creates the offer with
  `offered_fare=0.00` and `missing_fare` (no funnel loss).
- **Reconciliation:** match → `confirmed`; divergence over tolerance → `discrepancy`
  and the server wins; unreadable OCR + valid local extraction → `client_only`.
- **Contract:** shape of the `GET /ride-offers/ocr/{uuid}` JSON in every state.
- **Accuracy acceptance:** ≥ 95 % on `offered_fare` and `estimated_distance_km`;
  ≥ 90 % on pickup address; `service_type` **never invented** (unclear → `null` +
  warning). Measured with OFFER-OCR-17/21.
- **Parity (OFFER-OCR-19):** same text fixtures and same `cases.json` in mobile and API;
  the local verdict and `server_verdict` must agree for the same payload.
- `composer test` (Pint + PHPStan level 7 + Pest) before closing.

---

## 10. Risks and mitigations

| Risk                                                          | Impact                            | Mitigation                                                                                          |
| :------------------------------------------------------------ | :-------------------------------- | :-------------------------------------------------------------------------------------------------- |
| Confusing pickup with trip                                    | Totally wrong verdict             | `Viaje:` rule + explicit separation + regression test                                               |
| Semi-transparent panel fill (Uber) leaves map text             | False positives (`Ak 491 (377)`)  | Colour crop + `x` filter + format validation                                                        |
| `spa`/`eng` misread small type                                | Fragile labels (`Exclusivo`)      | Double `psm`, context inference and optional `service_type`                                         |
| Tesseract binary missing in production                        | Mass job failure                  | Preflight check + `provider_unavailable` + Azure Read fallback                                      |
| Duplicate captures                                            | Inflated metrics                  | `image_hash` + window + deterministic `client_uuid`                                                 |
| PII in captures (names, addresses, phones in the status bar)   | Compliance                        | Strict panel crop, no status-bar text persisted, image retention and deletion                       |
| Uber/DiDi UI changes                                          | Stale parsers                     | Catalogue + fixtures + harness + `raw_payload` for diagnosis                                        |
| Azure cost                                                    | Billing                           | Tesseract by default; Azure only as a configurable fallback                                         |
| Mobile offline or API down                                    | No verdict                        | Local extraction + calculation; upload retried from the offline queue                               |
| Battery/CPU on device                                         | Bad experience                    | ML Kit, process only the cropped panel, do not re-encode the uploaded image                         |
| Mobile ↔ server divergence                                    | Inconsistent metrics              | Explicit per-field reconciliation + fixture parity in both repos                                    |
| Duplicates from offline retry                                 | Inflated funnel                   | Stable `client_uuid` + idempotent `200` + `image_hash` + ±120 s window                             |

---

## 11. Closed and pending decisions

### Closed

1. **Tesseract by default** on the server; Azure Read is an optional fallback.
2. **No driver confirmation:** every detected offer is registered automatically; a
   low-confidence scan registers the offer with the available fields and a warning.
3. **Mobile is the critical path:** native extraction (ML Kit/Vision) + local calculation
   with `gig-economics`; the image is uploaded afterwards and processed by the API in
   the background.
4. **`service_type` is never invented** when there is no label
   (`null` + `service_type_raw` + warning).

### Pending (low impact, suggested decisions)

1. **Per-country catalogue:** suggestion → global catalogue with aliases; `country_code`
   only enables/disables types per market if a real conflict appears.
2. **Real fixtures:** the `oferta_*.jpeg` files contain real Quito addresses and names;
   anonymise or replace them before committing (test-data rule), and use the synthetic
   `*.txt` files as the source of truth for tests.
3. **Image retention:** suggestion 30 days, configurable; the extraction is kept and the
   file is deleted.