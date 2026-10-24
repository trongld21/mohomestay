<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/retail-stock-alerts.php';

$mailSettings = ['admin_email' => 'admin@example.test', 'mail.from' => 'sender@example.test'];
function config(string $key, mixed $default = null): mixed
{
    global $mailSettings;
    return $mailSettings[$key] ?? $default;
}
function stock_alert_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE retail_products (id TEXT PRIMARY KEY,name TEXT,stock_quantity INTEGER,track_stock INTEGER);
CREATE TABLE retail_stock_alert_state (product_id TEXT PRIMARY KEY,alert_open INTEGER DEFAULT 0,cycle_number INTEGER DEFAULT 0,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE retail_stock_alert_emails (id TEXT PRIMARY KEY,product_id TEXT,cycle_number INTEGER,recipient TEXT,payload TEXT,status TEXT DEFAULT 'PENDING',attempts INTEGER DEFAULT 0,claim_token TEXT,last_attempt_at TEXT,sent_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(product_id,cycle_number));
INSERT INTO retail_products VALUES ('water','Nước <suối>',10,1),('service','Phụ thu',0,0);");
$change = static function (int $quantity, string $id = 'water') use ($pdo): void {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE retail_products SET stock_quantity=? WHERE id=?')->execute([$quantity, $id]);
    retail_queue_low_stock_alert($pdo, $id);
    $pdo->commit();
};
$count = static fn(): int => (int)$pdo->query('SELECT COUNT(*) FROM retail_stock_alert_emails')->fetchColumn();
$change(6);
stock_alert_check($count() === 0, 'No alert above threshold.');
$change(5);
stock_alert_check($count() === 1, 'Threshold includes five.');
$change(4);
$change(-2);
$change(3);
stock_alert_check($count() === 1, 'A low cycle only queues one alert, including negative inventory.');
$change(0, 'service');
stock_alert_check($count() === 1, 'Services do not send low-stock alerts.');
$accepted = [];
$transport = static function (string $recipient, string $subject, string $html, array $headers) use (&$accepted, $pdo): bool {
    stock_alert_check($recipient === 'admin@example.test', 'Send only to configured admin.');
    stock_alert_check(str_contains($html, 'Nước &lt;suối&gt;'), 'Escape product name in email.');
    stock_alert_check(!str_contains($html, 'Nước <suối>'), 'Do not render product HTML.');
    stock_alert_check(isset($headers['Message-ID']), 'Use stable email identity.');
    $accepted[] = $headers['Message-ID'];
    stock_alert_check(dispatch_retail_stock_alerts($pdo, static fn(): bool => throw new RuntimeException('Concurrent duplicate'), time()) === 0, 'Claim prevents a concurrent dispatcher from sending the same alert.');
    return true;
};
$pdo->beginTransaction();
stock_alert_check(dispatch_retail_stock_alerts($pdo, $transport) === 0, 'Never send before stock transaction commits.');
$pdo->commit();
stock_alert_check(dispatch_retail_stock_alerts($pdo, $transport) === 1, 'Send queued message.');
stock_alert_check(dispatch_retail_stock_alerts($pdo, $transport) === 0 && count($accepted) === 1, 'Sent message is not resent.');
$change(6);
$change(5);
stock_alert_check($count() === 2, 'Replenishing above five rearms a new low cycle.');
$now = time();
stock_alert_check(dispatch_retail_stock_alerts($pdo, static fn(): bool => false, $now) === 0, 'Transport failure must remain retryable.');
stock_alert_check($pdo->query("SELECT COUNT(*) FROM retail_stock_alert_emails WHERE status='FAILED'")->fetchColumn() == 1, 'Failed outbox row retained.');
stock_alert_check(dispatch_retail_stock_alerts($pdo, static fn(): bool => throw new RuntimeException('Too soon'), $now) === 0, 'Retry is throttled.');
stock_alert_check(dispatch_retail_stock_alerts($pdo, static fn(): bool => true, $now + 901) === 1, 'Failed mail can be retried after delay.');
$pdo->beginTransaction();
$pdo->exec("UPDATE retail_products SET stock_quantity=10 WHERE id='water'");
retail_queue_low_stock_alert($pdo, 'water');
$pdo->exec("UPDATE retail_products SET stock_quantity=5 WHERE id='water'");
retail_queue_low_stock_alert($pdo, 'water');
$pdo->rollBack();
stock_alert_check($count() === 2, 'Rolled-back sales do not queue alerts.');
$change(6);
$change(5);
$mailSettings['mail.from'] = '';
stock_alert_check(dispatch_retail_stock_alerts($pdo, static fn(): bool => true) === 0, 'Unconfigured sender does not consume retry attempts.');
stock_alert_check((int)$pdo->query("SELECT attempts FROM retail_stock_alert_emails WHERE status='PENDING'")->fetchColumn() === 0, 'Leave attempts untouched until sender is configured.');
$mailSettings['mail.from'] = 'sender@example.test';
for ($attempt = 0; $attempt < 6; $attempt++) {
    dispatch_retail_stock_alerts($pdo, static fn(): bool => false, $now + $attempt * 901);
}
stock_alert_check((int)$pdo->query("SELECT attempts FROM retail_stock_alert_emails WHERE status='FAILED'")->fetchColumn() === 5, 'Bound automatic retries to five.');
echo "Low-stock cycle, admin recipient, transactional outbox, claims, retry and sender validation: OK\n";
