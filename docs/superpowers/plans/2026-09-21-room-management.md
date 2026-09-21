# Dynamic Room Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a polished responsive room-management dashboard with flexible packages, tags, safe multi-image upload, coming-soon publishing, dynamic public room pages, shared confirmation modals/toasts, and branded favicon.

**Architecture:** Normalize rooms, images, tags, and packages in MySQL and expose focused repository/service functions to both public and authenticated APIs. Keep image binaries in a deployment-excluded uploads directory. Replace hard-coded room data with database DTOs, then drive admin and public interfaces from JSON APIs while centralizing modal/toast behavior in shared browser utilities.

**Tech Stack:** PHP 8.1+, PDO MySQL/MariaDB, vanilla JavaScript, CSS, Apache/DirectAdmin HTTPS deploy hook.

**Spec:** `docs/superpowers/specs/2026-09-21-room-management-design.md`

## Global Constraints

- Before Task 1, discard the uncommitted draft changes in `src/bootstrap.php` and `database/migrations/002_room_management.sql`; they were exploratory code written before the test-first plan and must not be reused as implementation.
- PHP runtime stays dependency-free: no Composer and no Node.js on hosting.
- Existing booking concurrency locks, payOS verification, guest privacy, and DirectAdmin deployment remain intact.
- `ACTIVE`, `COMING_SOON`, and `HIDDEN` are the only room states.
- No fake ratings or reviews.
- No production JavaScript may call native `alert()` or `confirm()`.
- Administrator-uploaded files under `public_html/uploads/` must survive deployment.
- All destructive actions require the shared accessible confirmation modal; transient feedback uses shared toasts.
- Public UI is Vietnamese and responsive at 360 px, tablet, and desktop widths.

## Review Focus

- A room with bookings must return HTTP 409 on deletion and remain intact; test in Task 3.
- A coming-soon/hidden room or disabled package must never be bookable even with a forged request; test in Task 4.
- Malicious or mislabeled uploads (`.php`, SVG, oversized files, fake JPEG) must be rejected without writing a file; test in Task 5.
- Editing package price/timing must not change existing booking snapshots; test in Task 4.
- Deploying new code must not enumerate, overwrite, or delete `public_html/uploads/`; verify in Task 9.

---

### Task 1: Room domain validation and timing

**Files:**
- Create: `src/rooms.php`
- Create: `tools/room-test.php`
- Modify: `src/bootstrap.php`
- Modify: `src/booking.php`
- Test: `tools/room-test.php`

**Interfaces:**
- Produces: `normalize_slug(string): string`, `validate_room_input(array): array`, `validate_packages(array): array`, `package_window(array,string,string): array{DateTimeImmutable,DateTimeImmutable}`.
- Consumes: existing `BookingException`, timezone configuration, and `db()`.

- [ ] **Step 1: Write failing domain tests**

Test slug normalization (`"Phòng Mây  2" -> "phong-may-2"`), duplicate/blank tags, DURATION limits, FIXED_TIME next-day handling, invalid prices, and invalid room state. Assert explicit validation messages.

- [ ] **Step 2: Run tests and verify RED**

Run: `php tools/room-test.php`
Expected: FAIL because `src/rooms.php` and the desired functions do not exist.

- [ ] **Step 3: Implement minimal validators and timing**

Create pure functions with no database dependency. Return canonical room fields, tags, and packages; throw `BookingException` with HTTP 422 for invalid data. Use `Normalizer` only when available and provide a Vietnamese character transliteration fallback.

- [ ] **Step 4: Rewire booking quote to database package DTOs**

Change quote input from legacy package key to `packageId`; load authoritative room/package data through the repository added in Task 3. Preserve compatibility only for the migration seed IDs while deployed clients transition.

- [ ] **Step 5: Run focused and full tests**

Run: `php tools/room-test.php && php tools/test.php && php tools/check.php`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/rooms.php src/bootstrap.php src/booking.php tools/room-test.php
git commit -m "feat: add flexible room package domain"
```

### Task 2: Idempotent normalized database migration

**Files:**
- Replace: `database/migrations/002_room_management.sql`
- Modify: `tools/room-test.php`
- Test: `tools/room-test.php`

**Interfaces:**
- Produces tables/columns expected by `RoomRepository`: `rooms.status`, `room_images`, `room_tags`, `room_packages`, and booking snapshot columns.
- Consumes existing IDs `pink`, `white`, and `black` without breaking foreign keys.

- [ ] **Step 1: Add schema-contract assertions**

Read migration SQL in the test and assert it creates all normalized tables, the three-state room enum, package timing constraints/columns, booking snapshot columns, foreign keys, and seeded packages for every legacy room.

- [ ] **Step 2: Run tests and verify RED**

Run: `php tools/room-test.php`
Expected: FAIL because the current draft migration lacks normalized tags/packages, status, and snapshots.

- [ ] **Step 3: Implement migration**

Create additive columns and normalized tables using MariaDB 10.3-compatible SQL. Seed three packages for each legacy room with stable IDs. Populate booking snapshot fields from legacy `stay_package` and `total_price`. Ensure rerunning after the migration record is safe and existing booking foreign keys remain valid.

- [ ] **Step 4: Run schema-contract and full tests**

Run: `php tools/room-test.php && php tools/test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/002_room_management.sql tools/room-test.php
git commit -m "feat: migrate rooms to flexible packages"
```

### Task 3: Room repository and deletion policy

**Files:**
- Create: `src/room-repository.php`
- Modify: `src/bootstrap.php`
- Modify: `tools/room-test.php`
- Test: `tools/room-test.php`

**Interfaces:**
- Produces: `RoomRepository::publicRooms()`, `allRooms()`, `findPublicBySlug()`, `findById()`, `create()`, `update()`, `delete()`, and DTOs containing nested `images`, `tags`, `packages`.
- Consumes validators from Task 1 and normalized schema from Task 2.

- [ ] **Step 1: Write failing repository policy tests**

Use an in-memory fake gateway around repository policy functions to assert: public rooms exclude hidden; active booking data prevents deletion; a room without bookings is deletable; create/update replaces nested tags/packages transactionally; only one cover image can be selected.

- [ ] **Step 2: Run and verify RED**

Run: `php tools/room-test.php`
Expected: FAIL because repository classes/policies are missing.

- [ ] **Step 3: Implement repository**

Use prepared PDO queries, transactions for room + tag + package writes, stable package IDs on update, and one DTO mapping function. Throw 404 for missing entities and 409 when deletion would break booking history. Delete managed files only after a successful database commit.

- [ ] **Step 4: Remove legacy static room authority**

Make `rooms()` a compatibility wrapper over `RoomRepository::publicRooms()` and keep bundled room definitions only as a guarded pre-migration fallback. Make `find_room()` use repository lookups.

- [ ] **Step 5: Run tests**

Run: `php tools/room-test.php && php tools/test.php && php tools/check.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/room-repository.php src/bootstrap.php tools/room-test.php
git commit -m "feat: add transactional room repository"
```

### Task 4: Public room and booking APIs

**Files:**
- Modify: `src/api.php`
- Modify: `src/booking.php`
- Modify: `tools/room-test.php`
- Test: `tools/room-test.php`

**Interfaces:**
- Produces: `GET /api/rooms`, `GET /api/rooms/{slug}`, and booking requests with `roomId`, `packageId`, `date`, `time`.
- Consumes repository DTOs and `package_window()`.

- [ ] **Step 1: Write failing eligibility/snapshot tests**

Test forged booking attempts against COMING_SOON/HIDDEN rooms, disabled packages, packages belonging to another room, stale client prices, and edits after booking. Assert the server computes and stores authoritative package snapshots.

- [ ] **Step 2: Run and verify RED**

Run: `php tools/room-test.php`
Expected: FAIL on current hard-coded package behavior.

- [ ] **Step 3: Implement public APIs and booking snapshot flow**

Return only ACTIVE and COMING_SOON public rooms; include all enabled packages only. Resolve package server-side inside the room lock, calculate start/end and price, and persist package ID/name/timing snapshot. Availability returns ACTIVE rooms only for booking eligibility while retaining public coming-soon display metadata.

- [ ] **Step 4: Run tests**

Run: `php tools/room-test.php && php tools/test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/api.php src/booking.php tools/room-test.php
git commit -m "feat: expose dynamic rooms and package booking"
```

### Task 5: Authenticated room CRUD and secure images

**Files:**
- Create: `src/uploads.php`
- Modify: `src/api.php`
- Modify: `src/room-repository.php`
- Create: `tools/upload-test.php`
- Test: `tools/upload-test.php`

**Interfaces:**
- Produces admin room CRUD endpoints and image upload/update/delete endpoints from the spec.
- Produces `validate_room_upload(array,string): array{extension,mime,size}` and `managed_upload_path(string,string): string`.
- Consumes admin session, CSRF, repository, and PHP `finfo`.

- [ ] **Step 1: Write failing upload and endpoint-policy tests**

Generate real 1×1 JPEG/PNG/WebP fixtures and fake PHP/SVG/mislabeled files. Assert valid MIME acceptance, 8 MiB/12-file limits, random safe names, traversal rejection, managed-path deletion rules, CSRF requirement, and 409 deletion behavior.

- [ ] **Step 2: Run and verify RED**

Run: `php tools/upload-test.php`
Expected: FAIL because upload functions/endpoints do not exist.

- [ ] **Step 3: Implement CRUD endpoints**

Route REST-like admin paths, accept validated JSON for room/tags/packages, return consistent `{room}` or `{error,fields}` payloads, and guard every mutation with admin session + CSRF.

- [ ] **Step 4: Implement secure image storage**

Validate upload error, byte size, `finfo` MIME, actual image dimensions, maximum dimensions, and file count. Store random names in `public/uploads/rooms/<room-id>/`. Add a generated `.htaccess` in the uploads root to deny script execution. Keep cover selection transactional.

- [ ] **Step 5: Run tests**

Run: `php tools/upload-test.php && php tools/room-test.php && php tools/test.php && php tools/check.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/uploads.php src/api.php src/room-repository.php tools/upload-test.php
git commit -m "feat: add secure room CRUD and image uploads"
```

### Task 6: Shared modal, toast, and favicon foundation

**Files:**
- Create: `public/favicon.svg`
- Create: `public/apple-touch-icon.svg`
- Create: `public/assets/ui.js`
- Modify: `src/views.php`
- Modify: `public/assets/app.css`
- Create: `tools/ui-test.php`
- Test: `tools/ui-test.php`

**Interfaces:**
- Produces global `LangUI.toast({type,title,message,persistent})` and `LangUI.confirm({title,message,confirmLabel,tone}): Promise<boolean>`.
- Consumes no application-specific APIs.

- [ ] **Step 1: Write failing static UI contract tests**

Assert every rendered page links `/favicon.svg`, includes one toast region and one dialog root, `ui.js` exposes the required functions, CSS includes reduced-motion rules, and production JS contains neither `alert(` nor `confirm(`.

- [ ] **Step 2: Run and verify RED**

Run: `php tools/ui-test.php`
Expected: FAIL for missing favicon/UI manager and native admin calls.

- [ ] **Step 3: Implement branded assets and UI manager**

Create a compact line-house SVG favicon. Build an accessible modal with focus trap, Escape/cancel behavior, focus restoration, pending protection, and ARIA labels. Build stacked ARIA-live toasts with semantic variants, close controls, timers, and pause-on-hover.

- [ ] **Step 4: Add polished shared styling**

Add modal backdrop/sheet behavior, toast transitions, skeletons, buttons, badges, chips, upload drop zone, empty states, and responsive rules. Disable nonessential transitions under `prefers-reduced-motion`.

- [ ] **Step 5: Run tests**

Run: `php tools/ui-test.php && node --check public/assets/ui.js && php tools/check.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add public/favicon.svg public/apple-touch-icon.svg public/assets/ui.js public/assets/app.css src/views.php tools/ui-test.php
git commit -m "feat: add shared modal toast and favicon"
```

### Task 7: Responsive admin room dashboard

**Files:**
- Create: `public/assets/admin.js`
- Modify: `src/views.php`
- Modify: `public/assets/app.css`
- Modify: `public/assets/app.js`
- Modify: `tools/ui-test.php`
- Test: `tools/ui-test.php`

**Interfaces:**
- Consumes admin room/booking APIs and `LangUI`.
- Produces two-tab admin dashboard and room editor modal.

- [ ] **Step 1: Write failing admin UI contract tests**

Assert the admin markup exposes booking/room tabs, add-room trigger, room editor sections, image drop input, tag input, package template, unsaved-change marker, and no inline native dialogs.

- [ ] **Step 2: Run and verify RED**

Run: `php tools/ui-test.php`
Expected: FAIL because the dashboard/editor do not exist.

- [ ] **Step 3: Implement dashboard tabs and room cards**

Render responsive summary cards, desktop tables/mobile booking cards, filter controls, and room cards with cover/state/packages/tags. Persist the selected tab in the URL hash without full navigation.

- [ ] **Step 4: Implement room editor**

Create/edit room fields, dynamic tag chips, arbitrary package rows for both timing modes, status/order controls, field validation, dirty-state protection, and loading states. Upload multiple images separately, show progress/results, select cover, edit captions/order, and confirm image deletion.

- [ ] **Step 5: Replace booking native dialogs**

Use `LangUI.confirm` for status transitions and `LangUI.toast` for all success/failure feedback. Confirm room deletion, hiding ACTIVE rooms, image/package deletion, and abandoning dirty edits.

- [ ] **Step 6: Run tests**

Run: `php tools/ui-test.php && node --check public/assets/admin.js && node --check public/assets/app.js`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add public/assets/admin.js public/assets/app.js public/assets/app.css src/views.php tools/ui-test.php
git commit -m "feat: build responsive room management dashboard"
```

### Task 8: Dynamic polished public room experience

**Files:**
- Create: `public/assets/rooms.js`
- Modify: `src/views.php`
- Modify: `public/index.php`
- Modify: `public/assets/app.js`
- Modify: `public/assets/app.css`
- Modify: `tools/ui-test.php`
- Test: `tools/ui-test.php`

**Interfaces:**
- Consumes public room API, availability API, and flexible package DTOs.
- Produces dynamic homepage/list/detail/calendar/booking rendering.

- [ ] **Step 1: Write failing public UI contract tests**

Assert dynamic room card containers, tag chips, coming-soon badge/disabled action, flexible package selectors, slug route handling, responsive gallery markup, and escaped API text rendering.

- [ ] **Step 2: Run and verify RED**

Run: `php tools/ui-test.php`
Expected: FAIL because public rendering still assumes three static rooms/packages.

- [ ] **Step 3: Implement server-rendered dynamic pages**

Use repository data for SEO-friendly room cards, price table, selectors, sitemap room URLs, and slug details. Show coming-soon rooms publicly but never in bookable selectors. Render gallery, chips, capacity, and flexible package timing labels.

- [ ] **Step 4: Update interactive calendar and booking**

Remove `roomData`/`packageNames` constants. Hydrate safe JSON from the server or fetch `/api/rooms`; calculate preview windows from package DTOs; submit package IDs; show toast errors and retain inline field validation.

- [ ] **Step 5: Add polished responsive behavior**

Implement image thumbnail selection, subtle card/gallery transitions, loading skeletons, graceful empty states, mobile horizontal chip/package scrolling, and coming-soon overlays.

- [ ] **Step 6: Run tests**

Run: `php tools/ui-test.php && node --check public/assets/rooms.js && node --check public/assets/app.js && php tools/check.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add public/assets/rooms.js public/assets/app.js public/assets/app.css src/views.php public/index.php tools/ui-test.php
git commit -m "feat: render dynamic public room catalog"
```

### Task 9: Deployment allowlist, smoke tests, and production handoff

**Files:**
- Modify: `public/deploy-hook.php`
- Modify: `.github/workflows/deploy-directadmin.yml`
- Modify: `DEPLOY_DIRECTADMIN.md`
- Modify: `tools/check.php`
- Test: `tools/ui-test.php`, `tools/upload-test.php`, all existing tests

**Interfaces:**
- Consumes all runtime assets/migrations from prior tasks.
- Produces deployment support for favicon/new JS/migrations while preserving uploads.

- [ ] **Step 1: Write failing deploy/static assertions**

Assert hook allowlist accepts `favicon.svg`, `apple-touch-icon.svg`, `assets/admin.js`, `assets/rooms.js`, and migration SQL; rejects every `uploads/` path. Assert workflow never copies or finds `public/uploads` for deployment.

- [ ] **Step 2: Run and verify RED**

Run: `php tools/ui-test.php`
Expected: FAIL because new asset types are not yet allowed.

- [ ] **Step 3: Update deployment safely**

Expand only explicit asset paths/extensions, keep upload storage excluded, retain signed per-file HTTPS uploads, and document PHP upload size/extensions plus migration sequence.

- [ ] **Step 4: Run complete automated verification**

Run:

```bash
php tools/check.php
php tools/test.php
php tools/room-test.php
php tools/upload-test.php
php tools/ui-test.php
node --check public/assets/ui.js
node --check public/assets/admin.js
node --check public/assets/rooms.js
node --check public/assets/app.js
git diff --check
```

Expected: all PASS with no warnings.

- [ ] **Step 5: Run local HTTP smoke checks**

Start `php -S 127.0.0.1:8099 -t public public/index.php`, then verify HTTP 200 for `/`, `/rooms`, `/favicon.svg`, `/auth/login`, `/api/health`; verify unauthenticated admin APIs return 401 and unknown room slugs return 404.

- [ ] **Step 6: Manual browser QA checklist**

At 360 px, tablet, and desktop: verify admin tabs, create/edit room, arbitrary tags/packages, multiple image upload, cover/reorder/delete, dirty-close confirmation, toast stack, coming-soon public card, dynamic booking selector, keyboard modal focus, and reduced-motion behavior.

- [ ] **Step 7: Commit**

```bash
git add public/deploy-hook.php .github/workflows/deploy-directadmin.yml DEPLOY_DIRECTADMIN.md tools/check.php tools/ui-test.php
git commit -m "chore: deploy dynamic room management safely"
```
