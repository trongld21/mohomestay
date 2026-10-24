# Timeline booking implementation plan

Goal: On every shared hourly map, choose an empty cell, see only server-verified packages, highlight the selected stay, then confirm to enter guest details.

Architecture: Add a read-only package availability endpoint using existing package timing and inventory validation. Keep transient selection within the shared timeline, cancel stale requests, clear selection on refresh, and recheck before handoff to the existing booking page.

Tech stack: PHP, JavaScript, Tailwind CSS. Preserve existing working tree changes and booking authorization.

- [x] Add regression tests for available packages, conflicts, cleaning, holds, fixed times, overnight and disabled rooms/packages.
- [x] Implement server package availability and API routing, fail closed on database errors.
- [x] Add accessible package choices, selected-hour highlighting and confirmation to the shared map; clear stale state.
- [x] Prefill the existing booking page and focus guest details only after availability succeeds.
- [x] Build CSS, run project and focused tests, review diff, attempt UI/API checks and report unavailable checks.

Validation: Project PHP checks, authorization, availability, focused server package tests, JS syntax, timeline interval tests, booking interaction VM tests, CSS build and git diff --check passed. Fresh reviewer found no important correctness issues. Live db() returns MySQL driver error 1045; both availability endpoints correctly return HTTP 503. No browser is connected, so desktop/mobile visual and real browser console verification remain unverified. No deploy or commit requested.
