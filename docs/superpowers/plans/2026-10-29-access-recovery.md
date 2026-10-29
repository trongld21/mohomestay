# Booking access recovery implementation plan

Goal: recover missing booking codes and report real retry results.
Spec: ../specs/2026-10-29-access-recovery-design.md (approved in chat).
Architecture: preserve booking lock and remote-first reconciliation; explicit regenerate flag permits creation after a complete empty scan only. READY stays immutable. PHP, MySQL, Tailwind and browser JavaScript.

Review focus: ambiguous network outcomes, cancelled/unpaid/expired bookings, concurrent clicks, persistence failures, existing READY codes.

- [ ] Add failing SQLite integration tests for explicit regeneration of UNKNOWN, recovery without duplicate creation, rejected invalid bookings and truthful API results.
- [ ] Extend sync_booking_access with regenerate=false; implement retry/regenerate routes with real result/error and optional mail dispatch after success. Classify persistence failure without leaking secrets.
- [ ] Add Tạo lại mã and inline feedback to booking cards; preserve permissions and prevent concurrent clicks. Rebuild Tailwind.
- [ ] Run related and repository-required checks, exercise desktop/mobile interactions, review diff.
- [ ] Fetch/pull latest main, resolve conflicts, verify, commit and push main as requested. Check deployment status.

Execution: inline in this chat per user's instruction to proceed. Separate local clone because source directory is outside writable workspace; original checkout remains untouched. No additional planning approval needed: approved behavior is unchanged.
