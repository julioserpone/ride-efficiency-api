# Ride Efficiency API — Implementation Plan

> **Repo:** `ride-efficiency-api` (Laravel 13, PostgreSQL + PostGIS, Sanctum, Azure OCR, `julioserpone/gig-economics`; parity with the `gig-economics` npm package used by mobile)
>
> **Purpose:** Backend contract, persistence, calculations and safety infrastructure for mobile and web clients.
>
> **Tracking:** `[ ]` pending · `[-]` in progress · `[x]` done · `[!]` blocked. Sizes: S = 1, M = 2, L = 3. Progress = completed size / total size.

---

## 1. Current state (baseline)

### Implemented and tested
- [x] Sanctum token auth: issue / current user / revoke.
- [x] Countries and country-specific energy products.
- [x] Vehicles: list and create.
- [x] Daily shifts: list, start, show, close; per-platform earnings; profitability via `gig-economics`.
- [x] Energy invoices: upload (multipart/base64), Azure OCR queue, vendor resolution, line items, encrypted provider payloads, manual review, delete.
- [x] Ride trips: calculate/save completed rides, list, show; verdict + break-even + energy price fallback.
- [x] Stats: weekly, monthly, summary, efficiency.
- [x] Pest coverage for main features; Pint/PHPStan/Pest scripts.

### Gaps relevant to the vision
- No offer ingestion/history/evaluation model.
- No server-side profitability profile endpoint for local mobile calculation.
- No 2FA, sessions list, password reset/rotation.
- No Reverb/broadcasting.
- No PostGIS setup or spatial queries.
- No circles, locations, alerts, chat, driving events, traffic reports.
- No device tokens/push.
- No identity/KYC.
- No complete CRUD for vehicles/shifts/trips.
- No OpenAPI spec / generated contracts.
- No staging deployment scripts.
- No S3 media disk/IAM setup.

---

## 2. Architecture decisions

1. **Local-first offer verdict.** Mobile computes the verdict; the API receives processed offers asynchronously and recomputes server-side for analytics.
2. **Profitability Contract v1.** Shared formula/units/rounding/version between the **`gig-economics` npm package** (mobile) and `julioserpone/gig-economics` (API). Fixtures live in the API repo and are consumed by mobile CI.
3. **Idempotent sync.** `client_uuid` unique per user; batch endpoint supports partial success.
4. **Append-only offer events.** `ride_offer_events` for funnel/audit; `ride_offers.status` is a derived read model.
5. **PostGIS for geospatial data.** Use `geography(Point, 4326)`, GiST indexes and `ST_DWithin` for nearby queries. Verify extension support in Laravel Cloud and on the staging EC2 instance before implementation.
6. **S3 from the start, presigned and private.** Media and backups live in a private S3 bucket. Laravel validates type/size and signs short-lived **presigned PUT/GET URLs**; bytes go client → S3 directly, never through the API.
7. **Separate AWS identities.** A **`ride-efficiency-deployer` IAM user** (MFA, local profile) provisions and operates infrastructure from the CLI. The EC2 app runs with an **instance profile role** limited to its bucket/prefixes and CloudWatch Logs. Laravel Cloud uses its own credentials. No access keys in the repo.
8. **Push first, SMS later.** Android FCM ships first; iOS APNs is blocked until Apple Developer enrollment. `SmsSender` interface with a `null` driver.
9. **2FA with `pragmarx/google2fa`.** Custom endpoints for setup, confirmation, verification and recovery codes.
10. **Feature flags.** Safety/community/KYC behind flags so staging stays cheap and migrations can ship dark.
11. **Cost guardrails.** AWS Budget alert at `$1`, private bucket with Block Public Access, SSE-S3, no versioning, lifecycle rules and per-type size limits. No CloudFront, NAT Gateway or Elastic IP in staging.
12. **API Resources / explicit JSON.** Keep current response style; add resources where they reduce duplication without breaking existing shapes.

---

## 3. Release plan

| Release | Scope | Demo outcome |
| :--- | :--- | :--- |
| **R0** | OpenAPI skeleton, offer migrations, PostGIS, profitability profile | Contract published; mobile can build against it |
| **R1** | Offer sync/history/summary, server recompute | Mobile offer MVP persisted and analyzable |
| **R2** | Auth hardening (2FA, sessions, password reminder) | Secure accounts for web/mobile |
| **R3** | Circles, PostGIS locations, panic, Android push | Safety MVP |
| **R4** | Chat media (S3), driving risk, traffic | Community suite |
| **R5** | Identity/KYC | Verification foundation |
| **R6** | Laravel Cloud production | Production-ready API |

---

## 4. Epics and tasks

### 4.1 EPIC API-AUTH — Authentication & account security

- [ ] **API-AUTH-01 (M)** List active sessions/devices (token name, last used, IP, user agent) and revoke one/all.
- [ ] **API-AUTH-02 (S)** Authenticated password change with current-password confirmation.
- [ ] **API-AUTH-03 (M)** Password reset by email; staging uses Mailpit/log; production provider decided later (free tier preferred).
- [ ] **API-AUTH-04 (S)** Password-rotation **reminder only**: `password_changed_at`, configurable interval (default 6 months), response header/flag; never hard-lock.
- [ ] **API-AUTH-05 (L)** 2FA TOTP with `pragmarx/google2fa`: setup secret + QR payload, confirm, recovery codes, verify at login, disable.
- [ ] **API-AUTH-06 (S)** Rate limiting + progressive lockout for login, 2FA and reset.
- [ ] **API-AUTH-07 (S)** Auth audit log (login success/failure, 2FA enabled/disabled, token revoked).
- [ ] **API-AUTH-08 (M, optional)** WebAuthn/passkeys using existing `passkeys` table; document as convenience, not required.
- [ ] **API-AUTH-09 (S)** Token expiration/abilities per device; refresh on password change.
- [ ] **API-AUTH-10 (S)** Document client-side biometric contract (no biometric data server-side).

**Acceptance:** password login always works; 2FA optional and recoverable; sessions revocable; rotation is a reminder; no biometrics stored.

---

### 4.2 EPIC API-OFFER — Offers, profitability and history (MVP)

#### Contract & configuration
- [x] **API-OFFER-01 (M)** Publish `Profitability Contract v1`: formulas, units, rounding, thresholds, `calculation_version`. (`docs/PROFITABILITY_CONTRACT_V1.md`)
- [x] **API-OFFER-02 (M)** Shared fixtures `shared/profitability-fixtures/v1/cases.json` (20 cases: gasoline/electric, commissions, deadhead, marginal/reject, edge cases).
- [x] **API-OFFER-03 (S)** `GET /api/v1/profitability/profile`: vehicle economy, current energy price, commission defaults, country depreciation, target profit/km, currency, `calculation_version`, `updated_at`. (PR: pending)
- [ ] **API-OFFER-04 (S)** Cache profile per user/vehicle; invalidate on vehicle/invoice change.
- [ ] **API-OFFER-05 (S)** Cross-check the npm `gig-economics` package outputs against PHP in CI using the shared fixtures.

#### Data model
- [x] **API-OFFER-06 (M)** Migration `ride_offers`:
  `client_uuid`, `user_id`, `vehicle_id`, `daily_shift_id`, `platform`, `external_offer_id`, `currency_code`,
  `offered_fare`, `surge_amount`, `tip_amount`, `toll_amount`,
  `estimated_distance_km`, `estimated_duration_minutes`, `pickup_distance_km`,
  `actual_distance_km`, `actual_fare`, `actual_duration_minutes`,
  `source`, `ocr_confidence`, `raw_payload`,
  `status`, `expires_at`, `received_at`, `decided_at`, `completed_at`, `cancelled_at`, `cancellation_reason`,
  `client_verdict`, `client_net_profit_estimate`, `client_break_even_fare`,
  `server_verdict`, `server_net_profit_estimate`, `server_break_even_fare`,
  `calculation_version`, `config_snapshot`, `ride_trip_id`.
- [x] **API-OFFER-07 (S)** Migration `ride_offer_events`: offer id, event type, occurred_at, metadata.
- [x] **API-OFFER-08 (S)** Indexes: `(user_id, received_at)`, `(user_id, status)`, `(platform)`, unique `(user_id, client_uuid)`.
- [x] **API-OFFER-09 (S)** Models, factories, casts (decimal), relationships; no destructive backfill.
- [x] **API-OFFER-10 (S)** Nullable FK `ride_trips.ride_offer_id` or `ride_offers.ride_trip_id`; choose one direction and document.

#### Endpoints & rules
- [x] **API-OFFER-11 (M)** `POST /api/v1/ride-offers/sync`: batch upsert offers + events, idempotent, partial success, per-item errors.
- [x] **API-OFFER-12 (S)** `GET /api/v1/ride-offers`: filters (platform, status, date range), pagination, sorting.
- [x] **API-OFFER-13 (S)** `GET /api/v1/ride-offers/{id}`.
- [x] **API-OFFER-14 (S)** `POST /api/v1/ride-offers/evaluate`: server-side fallback for web/other clients; target p95 < 300 ms; not used by mobile critical path.
- [x] **API-OFFER-15 (S)** State machine: `received → evaluated → accepted/rejected/expired`; `accepted → completed/cancelled`; reject invalid transitions with 422.
- [x] **API-OFFER-16 (S)** Expiration job for `expires_at`; emits `expired` event.
- [x] **API-OFFER-17 (S)** Recompute server-side on sync; store `client_*` and `server_*` for drift analysis.
- [x] **API-OFFER-18 (M)** `GET /api/v1/ride-offers/summary/daily`: offered/evaluated/accepted/rejected/expired/completed/cancelled; acceptance/completion rates; avg fare/profit.
- [ ] **API-OFFER-19 (M, optional)** Daily aggregate table/job (`daily_offer_metrics`) if volume warrants.
- [ ] **API-OFFER-20 (S)** Link completed `ride_trip` to originating offer; update actual fields.
- [ ] **API-OFFER-21 (S)** Form Requests, validation, ownership authorization, tests.

**Acceptance:** mobile can sync 100 offers with retries and 0 duplicates; API recomputes and stores both verdicts; daily funnel is queryable; invalid transitions rejected.

---

### 4.3 EPIC API-SAFETY — Circles, location and panic

#### PostGIS foundation
- [ ] **API-GEO-01 (M)** Enable PostGIS extension in local/staging/production; document version.
- [ ] **API-GEO-02 (S)** Add `location geography(Point, 4326)` columns and GiST indexes to location/alert/traffic tables.
- [ ] **API-GEO-03 (S)** Nearby query service using `ST_DWithin` and distance ordering.
- [ ] **API-GEO-04 (S)** Verify Laravel Cloud PostGIS support; define fallback if unavailable.

#### Circles and alerts
- [ ] **API-SAFE-01 (M)** Migrations: `circles`, `circle_members`, `circle_invites` (roles/permissions, unique invite code).
- [ ] **API-SAFE-02 (M)** Migration `driver_alerts` (panic, suspicious_zone, traffic_block, mechanical_issue) with PostGIS location.
- [ ] **API-SAFE-03 (M)** Migration `location_pings` + `user_locations` (latest position) with PostGIS location.
- [ ] **API-SAFE-04 (S)** Migration `device_tokens` (FCM/APNs, platform, last_seen) + notification preferences.
- [ ] **API-SAFE-05 (M)** Circles CRUD: create, list, show, join via invite, remove member, delete (owner).
- [ ] **API-SAFE-06 (S)** Permission matrix `family_lite` / `driver_peer` / `admin`; policies.
- [ ] **API-SAFE-07 (M)** `POST /locations` batch idempotent; update `user_locations`; retention policy.
- [ ] **API-SAFE-08 (S)** `GET /circles/{id}/locations`: latest positions by role; no financial fields for lite.
- [ ] **API-SAFE-09 (M)** `POST /driver-alerts`, `GET /driver-alerts` nearby, `PATCH /driver-alerts/{id}/resolve`.
- [ ] **API-SAFE-10 (S)** Panic rate limit, acknowledgment, cancellation, expiry.
- [ ] **API-SAFE-11 (M)** Android FCM push to circle members; free tier only.
- [ ] **API-SAFE-12 (S)** iOS APNs interface/task **blocked** until Apple Developer enrollment.
- [ ] **API-SAFE-13 (S)** `SmsSender` interface + `null` driver; feature flag for future provider.
- [ ] **API-SAFE-14 (M)** Privacy: consent, retention, deletion of location history; audit.

**Acceptance:** lite view never exposes earnings; peer receives Android panic push; panic can be cancelled; nearby alerts respect radius/expiry.

---

### 4.4 EPIC API-CHAT — Circle communication

- [ ] **API-CHAT-01 (M)** Configure the private S3 disk: Block Public Access, SSE-S3, no versioning, prefixes (`chat/`, `kyc/`, `tmp/`, `backups/`), lifecycle rules and a `$1` budget alert.
- [ ] **API-CHAT-02 (M)** `circle_messages` (text/audio/image, metadata, reply_to, created_at).
- [ ] **API-CHAT-03 (M)** Send/list paginated messages; membership authorization.
- [ ] **API-CHAT-04 (M)** Presigned upload flow: validate MIME/size, return presigned PUT for `chat/{circle}/{uuid}`, confirm object key, return short-lived presigned GET for reads.
- [ ] **API-CHAT-05 (S)** Orphan cleanup: delete `tmp/` objects after 24 h and unconfirmed uploads; lifecycle rule as backstop.
- [ ] **API-CHAT-06 (S)** Broadcast new message via Reverb; Android push notification.
- [ ] **API-CHAT-07 (S)** Report message, block user, moderation hooks.
- [ ] **API-CHAT-08 (S, optional)** Read receipts.

**Acceptance:** text/audio/image messages work; members receive realtime or push; abuse can be reported; media is private in S3 and only reachable through short-lived presigned URLs.

---

### 4.5 EPIC API-RISK — Driving risk

- [ ] **API-RISK-01 (M)** `driving_events` (speeding, distraction, harsh_brake, severity, location, recorded_at, metadata).
- [ ] **API-RISK-02 (M)** Batch ingestion; dedupe by client event id.
- [ ] **API-RISK-03 (S)** Aggregate risk score per shift/day.
- [ ] **API-RISK-04 (S)** Privacy: derived events only, no raw sensor streams.
- [ ] **API-RISK-05 (S, optional)** Notify peers on high-severity events (Android push first).

---

### 4.6 EPIC API-TRAFFIC — Road reports

- [ ] **API-TRAFFIC-01 (M)** `traffic_reports` (jam, road_closure, accident, hazard) with PostGIS location, `expires_at`, `is_active`.
- [ ] **API-TRAFFIC-02 (M)** Create, list nearby (PostGIS radius + expiry), resolve.
- [ ] **API-TRAFFIC-03 (S)** Confirmations/upvotes and automatic expiry.
- [ ] **API-TRAFFIC-04 (S)** Broadcast to circle channels.

---

### 4.7 EPIC API-IDENTITY — Verification (future)

- [ ] **API-ID-01 (L)** Identity document upload + selfie (private S3, encryption).
- [ ] **API-ID-02 (M)** Verification workflow pending/approved/rejected; admin review.
- [ ] **API-ID-03 (L)** Background check provider integration + webhook.
- [ ] **API-ID-04 (S)** Driver eligibility flag.
- [ ] **API-ID-05 (M)** PII consent, retention, deletion, audit.

---

### 4.8 EPIC API-COMPLETE — CRUD and contract completeness

- [ ] **API-COMP-01 (S)** Vehicles: show, update, deactivate.
- [ ] **API-COMP-02 (S)** Shifts: delete (or archive) with ownership.
- [ ] **API-COMP-03 (S)** Ride trips: update/delete.
- [ ] **API-COMP-04 (S)** User profile: update name/country; password endpoints from AUTH.
- [ ] **API-COMP-05 (S)** Consistent filters/sorting/pagination across lists.
- [ ] **API-COMP-06 (S)** Policies for ownership and roles.
- [ ] **API-COMP-07 (S)** Standard error envelope and validation shape.

---

### 4.9 EPIC API-REALTIME — Reverb and notifications

- [ ] **API-RT-01 (M)** Install/configure `laravel/reverb` + broadcasting; channels auth.
- [ ] **API-RT-02 (S)** Private/presence channels per user/circle.
- [ ] **API-RT-03 (M)** Events: `LocationUpdated`, `PanicAlertBroadcast`, `TripStatusChanged`, `CircleMessageSent`, `DrivingRiskDetected`, `TrafficReportCreated`.
- [ ] **API-RT-04 (S)** Outbox pattern for reliable events/notifications.
- [ ] **API-RT-05 (S)** Event versioning and idempotency.
- [ ] **API-RT-06 (S)** Android push service (FCM) + device token registration.
- [ ] **API-RT-07 (S)** iOS APNs adapter, **blocked** until Apple Developer enrollment.
- [ ] **API-RT-08 (S)** Notification preferences (push on/off per type).

---

### 4.10 EPIC API-PLATFORM — Deployment and operations

#### AWS staging (temporary, free tier)
- [ ] **API-PLAT-01 (M)** EC2 bootstrap: PHP 8.4, Composer, nginx, PostgreSQL + PostGIS, queue worker, scheduler, private S3 disk.
- [ ] **API-PLAT-02 (S)** DuckDNS hostname (e.g. `ride-efficiency.duckdns.org`) updated by cron every 5 minutes and on boot; Let's Encrypt **HTTP-01** via Caddy/acme.sh with automatic renewal. No custom domain.
- [ ] **API-PLAT-03 (M)** Deploy script: pull, composer, migrate, cache, queue restart.
- [ ] **API-PLAT-04 (S)** Start/stop scripts + schedule to avoid idle charges.
- [ ] **API-PLAT-05 (S)** Hardening: SSH keys, firewall, fail2ban, HTTPS, no public database port.
- [ ] **API-PLAT-06 (S)** Nightly `pg_dump` to `backups/` in S3, retention, restore drill.
- [ ] **API-PLAT-07 (S)** Billing guardrails: AWS Budget alert at **$1**, free-tier usage review, no CloudFront/NAT/Elastic IP.
- [ ] **API-PLAT-08 (S)** `docs/DEPLOYMENT_AWS.md` with limits, topology and exit plan.
- [ ] **API-PLAT-09 (M)** Create the **`ride-efficiency-deployer` IAM user** with MFA, least-privilege policies (EC2, S3, IAM roles, CloudWatch, SSM) and local AWS profile instructions; no keys in the repo.
- [ ] **API-PLAT-10 (S)** Create the **`ride-efficiency-ec2` instance profile role** scoped to the bucket/prefixes and CloudWatch Logs; attach it to the instance; verify no static keys on the server.
- [ ] **API-PLAT-11 (S)** Single private S3 bucket with prefixes and lifecycle: `tmp/` 24 h, chat configurable (~90 days), KYC configurable (~1 year), backups per policy.

#### Production Laravel Cloud
- [ ] **API-PLAT-12 (M)** Project/env, PostgreSQL + PostGIS, queue workers, scheduler.
- [ ] **API-PLAT-13 (M)** Reverb hosting strategy.
- [ ] **API-PLAT-14 (S)** Domain/TLS/CORS/secrets.
- [ ] **API-PLAT-15 (S)** S3 bucket, runtime credentials, lifecycle rules.
- [ ] **API-PLAT-16 (S)** Backups + restore drill.
- [ ] **API-PLAT-17 (S)** Observability: Sentry free tier, healthchecks, uptime, logs.
- [ ] **API-PLAT-18 (S)** CI/CD: GitHub Actions (lint, tests, staging deploy; prod via Laravel Cloud integration).

---

### 4.11 EPIC API-QA — Contracts, tests, docs

- [ ] **API-QA-01 (M)** OpenAPI 3.1 spec for all endpoints; publish.
- [ ] **API-QA-02 (M)** Contract tests for response shapes.
- [ ] **API-QA-03 (M)** Profitability parity fixtures PHP side + npm `gig-economics` cross-check.
- [ ] **API-QA-04 (S)** Arch tests (controller → action/service).
- [ ] **API-QA-05 (S)** Load test sync batch + evaluate p95.
- [ ] **API-QA-06 (S)** Security tests: ownership, roles, rate limits, PII.
- [ ] **API-QA-07 (S)** `migrate:fresh` + rollback in CI.
- [ ] **API-QA-08 (S)** Update README/API surface.
- [ ] **API-QA-09 (S)** ADRs: local calc parity, PostGIS, S3 media, push, SMS, 2FA, retention.

---

## 5. Data model additions (summary)

| Table | Purpose |
| :--- | :--- |
| `ride_offers` | Offer header + client/server verdicts + lifecycle |
| `ride_offer_events` | Append-only funnel/audit |
| `circles` | Safety/community circles |
| `circle_members` | Roles/permissions |
| `circle_invites` | Invite codes/links |
| `driver_alerts` | Panic and hazard alerts (PostGIS) |
| `location_pings` | Location history (PostGIS) |
| `user_locations` | Latest position (PostGIS) |
| `device_tokens` | Push registration |
| `circle_messages` | Chat text/audio/image |
| `driving_events` | Derived risk events (PostGIS) |
| `traffic_reports` | Road reports (PostGIS) |
| `identity_verifications` | KYC status (future) |

---

## 6. Testing strategy

- **Unit:** profitability calculation, state machine, PostGIS distance queries, role matrix.
- **Feature:** offer sync idempotency/partial success, auth 2FA, circles, alerts, chat media, traffic nearby.
- **Contract:** OpenAPI schema validation; response shapes.
- **Parity:** fixtures PHP ↔ npm `gig-economics`.
- **Load:** sync batch, evaluate, nearby queries.
- **Security:** ownership, roles, rate limits, PII leakage.
- **Migrations:** `migrate:fresh`, rollback, PostGIS extension.

---

## 7. Acceptance criteria for R1 (Offer MVP)

- [ ] `ride_offers` and `ride_offer_events` migrated and reversible.
- [ ] PostGIS enabled and spatial indexes present for future safety tables.
- [ ] `GET /profitability/profile` returns versioned config.
- [ ] `POST /ride-offers/sync` handles 100 offers idempotently with partial success.
- [ ] `GET /ride-offers` and `GET /ride-offers/{id}` work with filters.
- [ ] `GET /ride-offers/summary/daily` returns the funnel.
- [ ] Server recompute stores `server_*` alongside `client_*`.
- [ ] OpenAPI updated; contract tests pass.
- [ ] `composer test` green.
