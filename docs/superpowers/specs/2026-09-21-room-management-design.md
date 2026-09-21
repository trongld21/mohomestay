# Room Management and Interaction Design

## Goal

Turn rooms from hard-coded PHP data into fully managed MySQL content. Administrators can create, edit, publish, preview, hide, and—when safe—delete rooms; upload and organize multiple images; define arbitrary amenity tags; and define arbitrary pricing/time packages. Public pages immediately reflect published data without code changes.

## Constraints

- Runtime remains PHP 8.1+ with PDO MySQL/MariaDB and no Composer or Node.js on hosting.
- Existing bookings, payOS verification, concurrency locks, guest privacy, and DirectAdmin HTTPS deployment must remain intact.
- The deployment process must never overwrite administrator-uploaded images.
- No fabricated ratings or reviews are shown.
- Vietnamese is the primary UI language.
- All layouts must work at desktop, tablet, and mobile widths.
- Native `alert()` and `confirm()` are forbidden. Destructive actions use the shared confirmation modal; transient outcomes use shared toast notifications.

## Data Model

### rooms

Each room stores its identifier, unique slug, name, short description, full description, guest/bedroom/bathroom counts, display order, timestamps, and one of three states:

- `ACTIVE`: visible publicly and eligible for availability/booking when at least one package is enabled.
- `COMING_SOON`: visible publicly with a “Sắp ra mắt” treatment; calendar and booking entry points are disabled.
- `HIDDEN`: absent from public pages but retained in admin and historical booking joins.

The primary image is derived from one `room_images.is_cover` row. A legacy/fallback image is used only while migrating old rooms without images.

### room_images

Stores a random ID, room ID, public path, optional caption, display order, cover flag, and timestamp. A room can have any number of images but at most one cover image. Setting a new cover clears the previous cover in the same transaction.

Accepted uploads are JPEG, PNG, and WebP, maximum 8 MiB each and up to 12 files per request. The server validates bytes with `finfo`, ignores the client filename, generates a cryptographically random filename, and writes to `public_html/uploads/rooms/<room-id>/`. SVG is not accepted. Deleting a database image also removes the file only when it is under the managed uploads directory; bundled illustration files are never deleted.

### room_tags

Stores room ID, label, and display order. Labels are trimmed, 1–40 characters, unique per room case-insensitively, and displayed as compact chips. A room may have up to 30 tags.

### room_packages

Each package has a random ID, room ID, display name, pricing mode, timing fields, integer VND price, enabled flag, and display order.

Timing modes:

- `DURATION`: `duration_minutes` is required and ranges from 30 minutes to 30 days. The guest selects the start time; checkout is calculated by duration.
- `FIXED_TIME`: `check_in_time` and `check_out_time` are required. Checkout may be on the next day when it is not later than check-in. This supports overnight and day-use windows.

Names are 1–80 characters. Price is an integer from 0 through 1,000,000,000 VND. Disabled packages remain editable in admin but do not appear publicly.

Bookings store both the package ID and a snapshot of package name/timing/price so later package edits never change historical orders.

## Deletion and History

A room with any booking cannot be physically deleted. The API returns a conflict response instructing the admin to set it to `HIDDEN`. A room without bookings can be deleted after explicit confirmation; its tags, packages, image rows, and managed image files are removed. Package rows referenced by bookings are not deleted; disabling is the normal lifecycle after use.

## Admin Experience

The admin page becomes a responsive dashboard with two persistent tabs:

1. **Đơn đặt phòng**: current metrics and booking table/cards, status actions, filters, and responsive mobile cards.
2. **Quản lý phòng**: room cards with cover image, state badge, price/package summary, tags, edit/preview/state controls, and “Thêm phòng”.

The room editor is a large modal/drawer:

- Basic information and state.
- Capacity and ordering.
- Multi-image drop zone with progress/validation, thumbnails, cover selection, reorder controls, captions, and deletion.
- Tag editor with Enter/comma creation and removable chips.
- Package builder allowing add, edit, reorder, enable/disable, and removal.
- Sticky save bar on desktop and mobile.

Unsaved editor changes trigger the shared confirmation modal before closing. Save buttons show pending state and prevent duplicate submissions.

## Public Experience

The homepage, room listing, room detail, search, calendar, and booking form consume the active/coming-soon room API payload instead of JavaScript/PHP constants.

Room cards show the cover image, amenity chips, capacity, enabled package prices, and state. `COMING_SOON` cards receive a polished overlay/badge and a disabled “Sắp ra mắt” action. Detail pages use a responsive gallery with a main image and thumbnails. Only `ACTIVE` rooms and enabled packages appear in calendar/booking selectors.

Routes use room slugs. A hidden or unknown room returns 404 publicly; admin preview can render hidden rooms through an authenticated preview endpoint or query.

## Shared Interaction Rules

The application provides one accessible modal manager and one toast manager:

- Confirmation modal is required for deleting rooms/images/packages, hiding a live room, changing booking state, and abandoning unsaved changes.
- Toasts report success, failure, warnings, and neutral information. They are keyboard accessible, announced with ARIA live regions, dismissible, stacked, and automatically expire except for critical errors.
- Inline validation remains next to invalid form fields; toasts do not replace field-level errors.
- Buttons show loading state, disable duplicate actions, and retain focus correctly after modal close.
- Motion uses short opacity/transform transitions. `prefers-reduced-motion: reduce` disables nonessential animation.

These interaction rules are project-wide and apply to future actions.

## Favicon and Brand

Add a compact SVG favicon based on the existing Lặng Home line-house mark, plus an Apple touch icon or PNG fallback when practical. Every page links the favicon and keeps the existing brown/cream brand palette.

## API Design

Public:

- `GET /api/rooms`: public rooms and enabled packages/tags/images; excludes hidden rooms.
- `GET /api/rooms/{slug}`: public room detail.
- Availability and booking endpoints accept room and package IDs and re-read authoritative timing/price from the database.

Admin, all session + CSRF protected:

- `GET /api/admin/rooms`
- `POST /api/admin/rooms`
- `PATCH /api/admin/rooms/{id}`
- `DELETE /api/admin/rooms/{id}`
- `POST /api/admin/rooms/{id}/images` using multipart form data
- `PATCH /api/admin/rooms/{id}/images/{imageId}` for cover/caption/order
- `DELETE /api/admin/rooms/{id}/images/{imageId}`

Room create/update accepts the room fields, tags, and packages as one validated JSON document. Database changes run in one transaction. Image binary uploads are separate so large uploads do not invalidate otherwise valid room edits.

## Error Handling and Security

- Admin endpoints return consistent JSON `{error, fields?}` with appropriate 400/401/404/409/413/422/500 status codes.
- Slugs are lowercase ASCII with hyphens and are unique.
- All output is escaped; admin text is never injected as raw HTML.
- Upload directories do not execute PHP. Managed uploads use randomized names and verified image MIME types.
- State/package eligibility and price are always enforced server-side, never trusted from the browser.
- Room booking uses the existing row lock before overlap checks.

## Migration and Deployment

A new idempotent migration expands `rooms`, creates image/tag/package tables, snapshots package fields on bookings, and converts the three existing room prices into package rows. Existing room IDs remain stable so historical bookings keep working.

The HTTPS deployment allowlist includes the favicon and application assets but excludes `public_html/uploads/`, ensuring administrator uploads survive deployments. Migration runs after application files upload.

## Testing

- Unit tests cover room/package validation, slug normalization, timing calculations, status eligibility, upload MIME/path rules, and deletion policy.
- API/database integration tests cover CRUD transactions, package snapshots, room deletion conflicts, cover-image uniqueness, and booking against disabled/coming-soon rooms.
- Static checks ensure no production JavaScript uses native `alert(` or `confirm(`.
- HTTP smoke tests cover favicon, public dynamic rooms, admin authentication, and the health endpoint.
- Manual responsive QA covers 360 px mobile, tablet, desktop, keyboard navigation, modal focus, toast announcements, gallery, image uploads, and reduced motion.
