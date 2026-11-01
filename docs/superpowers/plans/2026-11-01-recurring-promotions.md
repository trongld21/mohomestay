# Recurring promotions implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement task by task.

**Goal:** Configure recurring and returning-guest promotions, applying only the highest eligible discount.
**Architecture:** Shared PHP promotion service calculates authoritative prices, a dedicated admin page manages rules, and an isolated quote endpoint updates booking previews. Existing room/package discounts remain candidates with their inheritance intact.
**Tech Stack:** PHP 8.1+, MariaDB, vanilla JavaScript, compiled Tailwind.
**Spec:** docs/superpowers/specs/2026-11-01-recurring-promotions-design.md (approved).

## Global constraints

- Highest percentage only, on base package price; no discount on surcharges.
- Arrival time in Asia/Ho_Chi_Minh, inclusive date bounds, half-open clock bounds, end 24:00 supported.
- Returning guest: completed non-test stays before now, phone OR email; blank email never matches.
- Preserve agreed manual prices and historical booking snapshots; no push/deploy.
- Preserve previous uncommitted dialog fix; use UTF-8 LF and scoped formatting.

## Review focus

- Stale asynchronous quotes must not overwrite current selections or agreed prices.
- Public preview must not expose guest records and must fail closed on unavailable pricing.
- Fixed-time arrivals use actual computed arrival rather than submitted clock.
- Multiple matching identities count bookings once and do not merge people transitively.
- Existing database fixtures without the new schema must be migrated in integration tests.

## Task 1: Domain and persistence

Files: create src/promotions.php, database/migrations/030_recurring_promotions.sql, tools/promotions-test.php.
Interfaces: promotion_validate(array): array; promotion_list(PDO): array; promotion_save(PDO,array): array; promotion_price(PDO,array room,array package,DateTimeImmutable start,string phone,string email): array package; promotion_snapshot(PDO,string bookingId,array package): void.
- [ ] Write and run failing tests for weekday/date/clock bounds, max-discount selection, canonical phone/email and completed-stay counting.
- [ ] Implement validation, repository, calculator and idempotent schema with snapshot storage.
- [ ] Run php tools/promotions-test.php, expecting OK.

## Task 2: Admin configuration

Files: create src/promotions-view.php, public/assets/promotions.js; integrate src/accounts.php, src/api.php, src/views.php, public/index.php and navigation.
Interfaces: GET/POST /api/admin/promotions, PATCH /api/admin/promotions/{id}, permission rooms.manage; input uses name,type,percent,enabled,startDate,endDate,weekdays,startTime,endTime,minStays,roomIds,packageIds.
- [ ] Add regression checks for validation and permissions before implementation.
- [ ] Build accessible Tailwind list and create/edit form with repeat schedule and returning threshold.
- [ ] Verify syntax, API authorization and desktop/mobile interaction.

## Task 3: Booking integration

Files: src/bootstrap.php, src/api.php, src/manual-bookings.php, public/assets/manual-booking.js, public/assets/app.js or booking flow module, tools/promotion-booking-test.php.
Interfaces: POST /api/booking-price with roomId,packageId,date,time,guestPhone,guestEmail; returns only basePrice,price,percent,promotionName. CSRF required. Recompute on actual arrival inside booking transaction, persist snapshot after insert. Manual agreedPrice overrides automatic proposal once.
- [ ] Add failing transaction/quote tests for phone OR email, no stacking, surcharge exclusion and agreed price preservation.
- [ ] Connect public and admin previews with revision guards and server recomputation/snapshots.
- [ ] Run focused PHP/JS tests then required repository checks and CSS build.

## Task 4: Review and verification

- [ ] Fresh review of domain, UI, integration and design compliance; address concrete findings.
- [ ] Run php tools/check.php, ui-test.php, test.php, room-test.php, upload-test.php and related tests; node --check for modified JS; npm run build:css; git diff --check.
- [ ] Inspect real desktop/mobile UI and console; report any remaining limits and migration requirement.

## Execution decisions / progress

User approved design and explicitly requested execution; proceed without another approval round. Use a feature branch in the current workspace so the existing uncommitted dialog fix remains available; do not commit or deploy without request.

### Implementation record

- Tasks 1–3 implemented on feat/recurring-promotions; tests first confirmed missing calculator/quote integration, stale automatic agreed-price handling, and extra-hour snapshot name loss before fixes.
- Task 1: 28 SQLite domain checks pass; migration added with idempotent CREATE TABLE IF NOT EXISTS.
- Task 2: admin render/permission checks pass; read permission rooms.view, write permission rooms.edit (the existing permission replaces the proposed rooms.manage name).
- Task 3: authoritative locked room/package repricing, transactional immutable snapshots, and automatic/edited manual-price separation implemented. Transaction tests cover email-only returning recognition, max discount, retries and agreed overrides. Public/admin asynchronous quote tests and existing booking routing/selection tests pass.
- Task 4: fresh independent reviewer found three material issues; all fixed and final review reports no remaining material blocker. Required PHP suites, related tests, modified JS syntax, compiled CSS build and git diff --check pass.
- Verification limit: php tools/migrate.php fails with database authentication error 1045; real MariaDB migration and live database booking tests remain unverified. No credentials or database services changed.
- Verification limit: browser host unavailable; Windows computer-use stopped because it could not determine the browser URL confidently. Desktop/mobile visual interaction and console inspection remain unverified. Temporary isolated QA router/server removed/stopped.
- Ruling: execute the already approved design without a second plan-approval pause, as explicitly requested by the user. No commit, push or deploy performed.
