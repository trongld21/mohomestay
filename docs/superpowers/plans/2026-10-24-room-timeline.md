# Room timeline and late checkout implementation plan

> **For agentic workers:** Use superpowers:executing-plans to implement task-by-task.

**Goal:** Staff can search free intervals, guests see a room timeline, authorized staff can lock overstays and collect late checkout charges.
**Architecture:** Share availability intervals and emergency holds across search, public calendars and booking mutations. Keep immutable checkout records with separate payOS payment identities.
**Tech Stack:** PHP, MySQL, vanilla JavaScript, Tailwind.
**Spec:** User request and approved grace rule: ceil(max(0, actual checkout - scheduled checkout - 900 seconds) / 3600) × 50000 VND.

## Constraints and decisions
- Keep existing uncommitted email changes; no push/deploy.
- UTC storage, Asia/Ho_Chi_Minh inputs and display.
- Public responses contain no booking identities, guest information or staff notes.
- Emergency locks are open-ended until confirmed checkout, including across midnight.
- Scheduled end is not confirmed departure. Preserve scheduled checkout for billing.
- A later reservation overlapping a lock is warned about, never silently cancelled.
- Payment failure leaves a fixed unpaid charge that can be retried with the same order code.
- Separate view, lock and checkout permissions; mutations require CSRF.
- Use existing payOS hosted QR payment page; no external QR image service receives bank payloads.

## Tasks
- [x] Add domain tests for fee boundaries, lifecycle, holds, overlapping and adjacent windows, duplicate checkout and callback.
- [x] Add idempotent migration and transactional locks/checkout ledger.
- [x] Integrate locks into availability, online/manual bookings, early arrival and payment callback.
- [x] Add staff search screen and public room matrix with accessible controls and privileged staff panel.
- [x] Add checkout/QR dialog and payment webhook reconciliation.
- [ ] Run PHP suites, JavaScript checks, CSS build, actual DB/API and desktop/mobile browser checks.

## Review focus
Race between booking and locking; lock across midnight; 15-minute fee boundary; QR retry after provider timeout; public information disclosure.

## Additional user requirements and verification ledger
- Added quote holds: separate availability.hold grant, exact package interval, expires after 3600 seconds, explicit release under room lock.
- Added shared 3600-second cleaning buffer after checkout; actual confirmed departure replaces scheduled end for completed stays.
- Removed one-hour early TTLock activation. GENERATED rounds up (documented hardware limit); CUSTOM writes exact minute through gateway, configured explicitly with TTLOCK_CODE_MODE.
- Existing physical/generated codes are retained. No live lock/network mutations were made by tests.
- Domain/SQLite transactions, permission, TTL, fee, callback, privacy and client interval tests pass. Tailwind built.
- Actual DB connection and migration remain blocked by MySQL 1045 after retry with updated .env.
- Real HTTP /rooms/pink renders timeline; /api/availability returns 503 due DB. Admin page redirects to login and anonymous operations return 401.
- Browser inventory is empty; creating an iab tab reports browser unavailable. Desktop/mobile visual and console QA remain unverified.
- No commit, push or deploy; .env never printed or staged.
