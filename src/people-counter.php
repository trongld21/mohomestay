<?php

declare(strict_types=1);

require_once __DIR__ . '/people-counter-preview.php';

function people_counter_authorized(string $key, string $timestamp, string $signature, string $body, int $now): bool
{
    return strlen($key) >= 32
        && preg_match('/^[0-9]{10}$/D', $timestamp) === 1
        && abs($now - (int)$timestamp) <= 300
        && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
        && hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $key), $signature);
}

function people_counter_time(mixed $value, int $now): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
        throw new BookingException('Thời điểm không hợp lệ.', 422);
    }
    $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value
        || $time->getTimestamp() > $now + 60 || $time->getTimestamp() < $now - 31 * 86400) {
        throw new BookingException('Thời điểm phải là UTC, trong 31 ngày gần nhất và không ở tương lai.', 422);
    }
    return $time->format('Y-m-d H:i:s');
}

function people_counter_ingest(PDO $pdo, array $input, int $now): array
{
    $trial = $input['trial'] ?? false;
    if (!is_bool($trial)) throw new BookingException('Chế độ đếm không hợp lệ.', 422);
    $table = $trial ? 'people_counter_trial_events' : 'people_counter_events';
    $cameraId = $input['cameraId'] ?? null;
    $status = $input['status'] ?? null;
    $events = $input['events'] ?? null;
    if (!is_string($cameraId) || !preg_match('/^[a-z0-9_-]{1,64}$/D', $cameraId)
        || !in_array($status, ['RUNNING', 'OFFLINE', 'ERROR'], true)
        || !is_array($events) || !array_is_list($events) || count($events) > 100) {
        throw new BookingException('Dữ liệu bộ đếm không hợp lệ.', 422);
    }
    $frame = ($input['frameAt'] ?? null) === null ? null : people_counter_time($input['frameAt'], $now);
    if ($status === 'RUNNING' && ($frame === null || strtotime($frame . ' UTC') < $now - 90)) {
        throw new BookingException('Trạng thái đang đếm cần khung hình trong 90 giây gần nhất.', 422);
    }
    $validated = [];
    foreach ($events as $event) {
        if (!is_array($event) || !is_string($event['id'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $event['id'])
            || !in_array($event['direction'] ?? null, ['IN', 'OUT'], true)) {
            throw new BookingException('Lượt qua cửa không hợp lệ.', 422);
        }
        $validated[] = [$event['id'], $event['direction'], people_counter_time($event['occurredAt'] ?? null, $now)];
    }
    $received = gmdate('Y-m-d H:i:s', $now);
    $pdo->beginTransaction();
    try {
        // Serialize batches per camera so concurrent retries cannot count twice.
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $camera = $pdo->prepare('SELECT id FROM people_counter_cameras WHERE id = ? AND enabled = 1' . $lock);
        $camera->execute([$cameraId]);
        if (!$camera->fetch()) {
            throw new BookingException('Camera chưa đăng ký hoặc đã tắt.', 422);
        }
        $find = $pdo->prepare('SELECT direction, occurred_at FROM ' . $table . ' WHERE camera_id = ? AND event_id = ?');
        $insert = $pdo->prepare('INSERT INTO ' . $table . '(camera_id,event_id,direction,occurred_at,received_at) VALUES(?,?,?,?,?)');
        $inserted = 0;
        foreach ($validated as [$id, $direction, $at]) {
            $find->execute([$cameraId, $id]);
            $existing = $find->fetch();
            if ($existing) {
                if ($existing['direction'] !== $direction || $existing['occurred_at'] !== $at) {
                    throw new BookingException('ID lượt đã tồn tại với dữ liệu khác.', 409);
                }
                continue;
            }
            $insert->execute([$cameraId, $id, $direction, $at, $received]);
            $inserted++;
        }
        $update = $pdo->prepare('UPDATE people_counter_cameras SET status = ?, last_received_at = ?, last_frame_at = ? WHERE id = ?');
        $update->execute([$status, $received, $frame, $cameraId]);
        $pdo->commit();
        return ['accepted' => count($validated), 'inserted' => $inserted];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function people_counter_report(PDO $pdo, array $filter, int $now): array
{
    $mode = $filter['mode'] ?? 'operational';
    if (!in_array($mode, ['operational', 'trial'], true)) throw new BookingException('Chế độ báo cáo không hợp lệ.', 422);
    $table = $mode === 'trial' ? 'people_counter_trial_events' : 'people_counter_events';
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d');
    $from = $filter['from'] ?? $today;
    $to = $filter['to'] ?? $from;
    $cameraId = $filter['cameraId'] ?? 'entrance';
    $page = filter_var($filter['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
    if (!is_string($from) || !is_string($to) || !is_string($cameraId) || !$page
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to)
        || !preg_match('/^[a-z0-9_-]{1,64}$/D', $cameraId)) {
        throw new BookingException('Bộ lọc không hợp lệ.', 422);
    }
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
    if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
        || $start > $end || $start->diff($end)->days > 30) {
        throw new BookingException('Chọn khoảng ngày hợp lệ, tối đa 31 ngày.', 422);
    }
    $cameras = $pdo->query('SELECT id,name,enabled,last_received_at,last_frame_at,status FROM people_counter_cameras ORDER BY name')->fetchAll();
    $selected = null;
    foreach ($cameras as $camera) {
        if ($camera['id'] === $cameraId) $selected = $camera;
    }
    if ($selected === null) throw new BookingException('Camera không tồn tại.', 422);
    $state = $selected['status'];
    if (!$selected['enabled']) {
        $state = 'DISABLED';
    } elseif ($selected['last_received_at'] === null) {
        $state = 'WAITING';
    } elseif (strtotime($selected['last_received_at'] . ' UTC') < $now - 90
        || ($state === 'RUNNING' && (!$selected['last_frame_at'] || strtotime($selected['last_frame_at'] . ' UTC') < $now - 90))) {
        $state = 'STALE';
    }
    $utc = new DateTimeZone('UTC');
    $params = [$cameraId, $start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    $where = ' WHERE camera_id = ? AND occurred_at >= ? AND occurred_at < ?';
    $hours = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $hours[] = ['hour' => $hour, 'in' => 0, 'out' => 0];
    }
    $summary = $pdo->prepare("SELECT SUBSTR(occurred_at,1,13) AS utc_hour,
        SUM(CASE WHEN direction = 'IN' THEN 1 ELSE 0 END) AS entries,
        SUM(CASE WHEN direction = 'OUT' THEN 1 ELSE 0 END) AS exits
        FROM " . $table . $where . ' GROUP BY SUBSTR(occurred_at,1,13)');
    $summary->execute($params);
    $totals = ['in' => 0, 'out' => 0];
    foreach ($summary as $row) {
        $hour = (int)(new DateTimeImmutable($row['utc_hour'] . ':00:00', $utc))->setTimezone($zone)->format('G');
        $hours[$hour]['in'] += (int)$row['entries'];
        $hours[$hour]['out'] += (int)$row['exits'];
        $totals['in'] += (int)$row['entries'];
        $totals['out'] += (int)$row['exits'];
    }
    $count = $totals['in'] + $totals['out'];
    $pages = max(1, (int)ceil($count / 50));
    $page = min($page, $pages);
    $offset = ($page - 1) * 50;
    $query = $pdo->prepare('SELECT event_id,direction,occurred_at FROM ' . $table . $where
        . ' ORDER BY occurred_at DESC,event_id DESC LIMIT 50 OFFSET ' . $offset);
    $query->execute($params);
    $events = [];
    foreach ($query as $row) {
        $events[] = ['id' => $row['event_id'], 'direction' => $row['direction'], 'occurredAt' => str_replace(' ', 'T', $row['occurred_at']) . 'Z'];
    }
    return [
        'from' => $from, 'to' => $to, 'mode' => $mode, 'totals' => $totals, 'hours' => $hours,
        'events' => $events, 'count' => $count, 'page' => $page, 'pages' => $pages,
        'camera' => ['id' => $cameraId, 'name' => $selected['name'], 'state' => $state,
            'lastReceivedAt' => $selected['last_received_at'] ? str_replace(' ', 'T', $selected['last_received_at']) . 'Z' : null],
        'cameras' => array_map(static fn(array $item): array => ['id' => $item['id'], 'name' => $item['name']], $cameras),
    ];
}

function people_counter_api(string $method, bool $integration): never
{
    if ($method !== ($integration ? 'POST' : 'GET')) {
        header('Allow: ' . ($integration ? 'POST' : 'GET'));
        json_response(['error' => 'Phương thức không được hỗ trợ.'], 405);
    }
    $input = [];
    if ($integration) {
        $raw = file_get_contents('php://input', false, null, 0, 262145);
        if ($raw === false || strlen($raw) > 262144) json_response(['error' => 'Dữ liệu quá lớn.'], 413);
        if (!people_counter_authorized((string)env_value('PEOPLE_COUNTER_KEY', ''),
            (string)($_SERVER['HTTP_X_COUNTER_TIMESTAMP'] ?? ''),
            (string)($_SERVER['HTTP_X_COUNTER_SIGNATURE'] ?? ''), $raw, time())) {
            json_response(['error' => 'Không có quyền gửi dữ liệu.'], 401);
        }
        $input = json_decode($raw, true);
        if (!is_array($input)) json_response(['error' => 'JSON không hợp lệ.'], 422);
    }
    try {
        $preview = $integration ? people_counter_preview_validate($input['preview'] ?? null, time()) : null;
        $result = $integration ? people_counter_ingest(db(), $input, time()) : people_counter_report(db(), $_GET, time());
        if ($preview !== null) {
            people_counter_preview_save(APP_ROOT . '/storage/people-counter', $input['cameraId'], $preview);
        }
        json_response($result);
    } catch (BookingException $error) {
        json_response(['error' => $error->getMessage()], $error->httpStatus);
    } catch (Throwable $error) {
        error_log('People counter unavailable: ' . get_class($error));
        json_response(['error' => 'Chưa tải được bộ đếm. Kiểm tra kết nối database và migration 026_people_counter.'], 503);
    }
}
