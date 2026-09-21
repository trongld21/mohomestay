# Lặng Home — project instructions

- Next.js App Router, TypeScript strict, Tailwind, PostgreSQL/Prisma, NextAuth and payOS. Deploy target: Vercel.
- Brand name: Lặng Home. Colors: warm brown / cream. Copy: Vietnamese. Address: 82 đường B18, KDC 91B, Ninh Kiều, Cần Thơ. Hotline: 0357907153.
- `lib/rooms.ts` is the authoritative room/package price catalog. Rates are VND; never trust a client-submitted amount.
- Three physical rooms: Pink, White, Black. Support 3h, 6h and overnight. Do not invent overnight hours, cancellation terms, reviews, facilities or real availability. Photos are explicitly illustrative until supplied by owner.
- Booking timestamps use Asia/Ho_Chi_Minh (UTC+7), stored as PostgreSQL timestamptz. All inventory writers acquire the room row lock before checking overlaps. Adjacent intervals are permitted.
- Reservation holds expire after 15 minutes. Payment is confirmed only by authenticated webhook after matching amount and order code. Repeated webhooks are idempotent. Late/cancelled payments require reconciliation, never silent re-confirmation.
- No fake successful reservations or fake payment URLs. Without configuration, keep online booking disabled and show phone/Zalo contact.
- Never expose guest PII in the public calendar, log passwords or secrets, or allow public admin mutations. Guests do not need an account.
- Check `README.md` and `.env.example` for current setup. Prisma wrapper loads `.env.local`. Never run schema changes against an existing database without checking migration history.
- Validate changes with `npm test`, `npm run lint`, `npm run build`. Integration tests require an isolated `TEST_DATABASE_URL` whose database name includes `langhome_test`.
