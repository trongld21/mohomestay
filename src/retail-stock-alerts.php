<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-notifications.php';

const RETAIL_LOW_STOCK_THRESHOLD = 5;

/** Call within the stock mutation transaction, after updating the product quantity. */
function retail_queue_low_stock_alert(PDO $pdo, string $productId): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Low-stock alert state must be updated in the stock transaction.');
    }
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    // Stock writers lock the same product row first, serializing receipt/sale cycles.
    $query = $pdo->prepare('SELECT id,name,stock_quantity,track_stock FROM retail_products WHERE id=?' . $lock);
    $query->execute([$productId]);
    $product = $query->fetch();
    if (!$product) return;

    $query = $pdo->prepare('SELECT alert_open,cycle_number FROM retail_stock_alert_state WHERE product_id=?' . $lock);
    $query->execute([$productId]);
    $state = $query->fetch();
    if (!$state) {
        $pdo->prepare('INSERT INTO retail_stock_alert_state (product_id,alert_open,cycle_number) VALUES (?,0,0)')->execute([$productId]);
        $state = ['alert_open' => 0, 'cycle_number' => 0];
    }
    // An untracked service has no inventory alerts. A receipt above the threshold rearms.
    if (!(bool)$product['track_stock'] || (int)$product['stock_quantity'] > RETAIL_LOW_STOCK_THRESHOLD) {
        if ((int)$state['alert_open'] !== 0) {
            $pdo->prepare('UPDATE retail_stock_alert_state SET alert_open=0,updated_at=CURRENT_TIMESTAMP WHERE product_id=?')->execute([$productId]);
        }
        return;
    }
    if ((int)$state['alert_open'] === 1) return;

    $cycle = (int)$state['cycle_number'] + 1;
    $payload = json_encode([
        'productId' => $productId,
        'productName' => (string)$product['name'],
        'stockQuantity' => (int)$product['stock_quantity'],
        'threshold' => RETAIL_LOW_STOCK_THRESHOLD,
        'observedAt' => gmdate('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $recipient = trim((string)config('admin_email', ''));
    $pdo->prepare('UPDATE retail_stock_alert_state SET alert_open=1,cycle_number=?,updated_at=CURRENT_TIMESTAMP WHERE product_id=?')
        ->execute([$cycle, $productId]);
    $pdo->prepare('INSERT INTO retail_stock_alert_emails (id,product_id,cycle_number,recipient,payload) VALUES (?,?,?,?,?)')
        ->execute([bin2hex(random_bytes(16)), $productId, $cycle, $recipient, $payload]);
}

function build_retail_low_stock_email(array $payload): array
{
    $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $product = (string)($payload['productName'] ?? 'Sản phẩm');
    $quantity = (int)($payload['stockQuantity'] ?? 0);
    $when = (string)($payload['observedAt'] ?? '');
    if ($when !== '') {
        $when = (new DateTimeImmutable($when, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('H:i d/m/Y');
    }
    return [
        'subject' => 'Mơ Home · Hàng sắp hết · ' . str_replace(["\r", "\n"], ' ', $product),
        'html' => '<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#45382f">'
            . '<h2>Hàng sắp hết</h2><p>Sản phẩm <strong>' . $escape($product) . '</strong> có tồn kho còn <strong>' . $quantity . '</strong>.</p>'
            . '<p>Ngưỡng cảnh báo: tồn kho từ ' . RETAIL_LOW_STOCK_THRESHOLD . ' trở xuống.</p>'
            . '<p>Ghi nhận lúc ' . $escape($when) . ' (giờ Việt Nam). Số lượng có thể đã thay đổi sau thời điểm này.</p>'
            . '<p>Vào Quản trị → Bán hàng → Nhập kho &amp; tồn kho để kiểm tra và nhập thêm hàng.</p>'
            . '<p>Mỗi sản phẩm chỉ báo một lần trong một đợt tồn thấp. Hệ thống báo lại sau khi nhập lên trên ngưỡng rồi giảm xuống thấp lần nữa.</p>'
            . '</body></html>',
    ];
}

/** Run after commit; SMTP acceptance cannot be made atomic with database writes. */
function dispatch_retail_stock_alerts(PDO $pdo, ?callable $transport = null, ?int $now = null): int
{
    if ($pdo->inTransaction()) return 0;
    $from = trim((string)config('mail.from', ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return 0;
    $now ??= time();
    $retryBefore = gmdate('Y-m-d H:i:s', $now - 900);
    $query = $pdo->prepare("SELECT * FROM retail_stock_alert_emails WHERE attempts<5 "
        . "AND (status='PENDING' OR (status IN ('FAILED','SENDING') AND last_attempt_at<?)) ORDER BY created_at,id LIMIT 20");
    $query->execute([$retryBefore]);
    $sent = 0;
    foreach ($query->fetchAll() as $notification) {
        $recipient = trim((string)$notification['recipient']);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipient = trim((string)config('admin_email', ''));
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) continue;
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $claimToken = bin2hex(random_bytes(16));
        $claim = $pdo->prepare("UPDATE retail_stock_alert_emails SET status='SENDING',attempts=attempts+1,last_attempt_at=?,claim_token=?,recipient=? "
            . "WHERE id=? AND attempts<5 AND (status='PENDING' OR (status IN ('FAILED','SENDING') AND last_attempt_at<?))");
        $claim->execute([$stamp, $claimToken, $recipient, $notification['id'], $retryBefore]);
        if ($claim->rowCount() !== 1) continue;
        $ok = false;
        try {
            $payload = json_decode((string)$notification['payload'], true, 512, JSON_THROW_ON_ERROR);
            $ok = send_staff_notification_message(build_retail_low_stock_email($payload), $recipient, $transport, 'stock-' . $notification['id']);
        } catch (Throwable $error) {
            error_log('Low-stock email deferred; error type: ' . get_class($error));
        }
        $finish = $pdo->prepare('UPDATE retail_stock_alert_emails SET status=?,sent_at=? WHERE id=? AND claim_token=?');
        $finish->execute([$ok ? 'SENT' : 'FAILED', $ok ? $stamp : null, $notification['id'], $claimToken]);
        if ($ok) $sent++;
    }
    return $sent;
}

function try_dispatch_retail_stock_alerts(PDO $pdo): void
{
    try {
        dispatch_retail_stock_alerts($pdo);
    } catch (Throwable $error) {
        error_log('Low-stock alert dispatch deferred; error type: ' . get_class($error));
    }
}
