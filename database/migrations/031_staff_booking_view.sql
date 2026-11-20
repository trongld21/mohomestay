-- Apply the agreed booking access to the existing staff account only.
START TRANSACTION;

SET @staff_email = 'nhansu@mohomestay.vn';

-- Preserve the non-checkout grants carried by the old availability group.
UPDATE accounts
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'availability.view')
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"availability"') AND NOT JSON_CONTAINS(permissions, '"availability.view"');

UPDATE accounts
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'availability.lock')
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"availability"') AND NOT JSON_CONTAINS(permissions, '"availability.lock"');

UPDATE accounts
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'availability.hold')
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"availability"') AND NOT JSON_CONTAINS(permissions, '"availability.hold"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'availability')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"availability"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'availability.checkout')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"availability.checkout"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'bookings')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"bookings"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'bookings.overview')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"bookings.overview"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'bookings.create')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"bookings.create"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'bookings.update')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"bookings.update"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'bookings.payment')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"bookings.payment"');

UPDATE accounts
SET permissions = JSON_REMOVE(permissions, JSON_UNQUOTE(JSON_SEARCH(permissions, 'one', 'bookings.identity.download')))
WHERE email = @staff_email AND JSON_CONTAINS(permissions, '"bookings.identity.download"');

UPDATE accounts
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'bookings.view')
WHERE email = @staff_email AND NOT JSON_CONTAINS(permissions, '"bookings.view"');

UPDATE accounts
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'bookings.identity')
WHERE email = @staff_email AND NOT JSON_CONTAINS(permissions, '"bookings.identity"');

COMMIT;
