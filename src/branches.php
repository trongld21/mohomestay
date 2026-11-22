<?php

declare(strict_types=1);

// Branch selection belongs to the URL, never to a mutable session/cookie. Two
// open tabs can therefore work on different branches without moving a write.
function branch_id(): int
{
    if (PHP_SAPI === 'cli') {
        $value = getenv('MO_BRANCH');
        if ($value !== false && !in_array($value, ['1', '2'], true)) {
            throw new InvalidArgumentException('MO_BRANCH phải là 1 hoặc 2.');
        }
        return $value === '2' ? 2 : 1;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return preg_match('#^/chi-nhanh/2(?:/|$)#', $path) ? 2 : 1;
}

function branch_path(?string $path = null): string
{
    $path ??= parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return preg_replace('#^/chi-nhanh/[12](?=/|$)#', '', $path) ?: '/';
}

function branch_url(string $url, ?int $branch = null): string
{
    $branch ??= branch_id();
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')
        || preg_match('#^/(?:chi-nhanh/|assets/|images/|uploads/|__dev/)#', $url)) {
        return $url;
    }
    return ($branch === 2 ? '/chi-nhanh/2' : '') . $url;
}

function branch_label(): string
{
    return 'Chi nhánh ' . branch_id();
}

// The existing tables remain untouched as branch 1. Branch 2 has its own
// physical tables, foreign keys and migration ledger in the same hosting DB.
// Login accounts alone are shared. Derive the registry from the deployed schema
// files so a newly introduced module cannot silently share its business tables.
function branch_identifiers(): array
{
    static $identifiers;
    if ($identifiers !== null) {
        return $identifiers;
    }
    $identifiers = [];
    foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $file) {
        $sql = (string)file_get_contents($file);
        preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z0-9_]*)/i', $sql, $tables);
        preg_match_all('/\bCONSTRAINT\s+`?([a-z_][a-z0-9_]*)/i', $sql, $constraints);
        foreach (array_merge($tables[1], $constraints[1]) as $name) {
            if ($name !== 'accounts') {
                $identifiers[strtolower($name)] = 'b2_' . strtolower($name);
            }
        }
    }
    return $identifiers;
}

function branch_tables(): array
{
    $tables = [];
    foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $file) {
        preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z0-9_]*)/i', (string)file_get_contents($file), $matches);
        foreach ($matches[1] as $name) {
            if ($name !== 'accounts') {
                $tables[$name] = 'b2_' . $name;
            }
        }
    }
    return $tables;
}

function branch_sql(string $sql, bool $migration = false): string
{
    $identifiers = branch_identifiers();
    // Tokenize quoted values, identifiers and comments separately. Never replace
    // a guest value, JSON payload, result alias or a parameter's bound value.
    $pattern = '~\x27(?:\\\\.|\x27\x27|[^\x27\\\\])*\x27|"(?:\\\\.|""|[^"\\\\])*"|`(?:``|[^`])*`|/\*[\s\S]*?\*/|--[^\r\n]*|\#[^\r\n]*|[a-zA-Z_][a-zA-Z0-9_]*|[^\s]~';
    $tableExpected = false;
    $constraintExpected = false;
    $metadataIdentifierExpected = false;
    return preg_replace_callback($pattern, static function (array $match) use ($identifiers, $migration, &$tableExpected, &$constraintExpected, &$metadataIdentifierExpected): string {
        $token = $match[0];
        if (str_starts_with($token, '/*') || str_starts_with($token, '--') || str_starts_with($token, '#')) {
            return $token;
        }
        if ($token[0] === "'" || $token[0] === '"') {
            if (!$migration) {
                $tableExpected = false;
                return $token;
            }
            // Migrations also contain information_schema comparisons and SQL in
            // PREPARE strings. These are trusted schema source, not user values.
            $value = substr($token, 1, -1);
            $isMetadataIdentifier = $metadataIdentifierExpected;
            $metadataIdentifierExpected = false;
            if ($isMetadataIdentifier && isset($identifiers[strtolower($value)])) {
                return $token[0] . $identifiers[strtolower($value)] . $token[0];
            }
            if (preg_match('/^\s*(?:ALTER|CREATE|DROP|SELECT|UPDATE|INSERT|DELETE|REPLACE|SET|TRUNCATE)\s/i', $value)) {
                return $token[0] . branch_sql($value, true) . $token[0];
            }
            return $token;
        }
        $name = strtolower(trim($token, '`'));
        if ($token !== '=') {
            $metadataIdentifierExpected = $migration && in_array(strtoupper($name), ['TABLE_NAME', 'CONSTRAINT_NAME', 'INDEX_NAME', 'REFERENCED_TABLE_NAME'], true);
        }
        $replacement = $token;
        if (isset($identifiers[$name]) && ($migration || $tableExpected)) {
            $replacement = $token[0] === '`' ? '`' . $identifiers[$name] . '`' : $identifiers[$name];
        } elseif ($migration && $constraintExpected && preg_match('/^[a-z_][a-z0-9_]*$/', $name) && !str_starts_with($name, 'b2_')) {
            // SHOW CREATE also names formerly anonymous foreign keys.
            $replacement = $token[0] === '`' ? '`b2_' . $name . '`' : 'b2_' . $name;
        }
        $constraintExpected = $migration && strtoupper($name) === 'CONSTRAINT';
        if (in_array(strtoupper($name), ['FROM', 'JOIN', 'UPDATE', 'INTO', 'TABLE', 'REFERENCES', 'LIKE'], true)) {
            $tableExpected = true;
        } elseif (!($tableExpected && in_array(strtoupper($name), ['IF', 'NOT', 'EXISTS'], true))) {
            $tableExpected = false;
        }
        return $replacement;
    }, $sql) ?? throw new RuntimeException('Cannot route branch SQL.');
}

final class BranchPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(branch_sql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null
            ? parent::query(branch_sql($query))
            : parent::query(branch_sql($query), $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(branch_sql($statement));
    }

    public function execMigration(string $statement): int|false
    {
        return parent::exec(branch_sql($statement, true));
    }
}

function branch_html(string $html): string
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
            return $html;
        }
    }
    if (branch_id() !== 2) {
        return $html;
    }
    return preg_replace_callback('~<(?:a|form)\b[^>]*>~i', static function (array $tag): string {
        if (str_contains($tag[0], 'data-branch-switch')) {
            return $tag[0];
        }
        return preg_replace_callback('~\b(href|action)=("|\x27)(/[^"\x27]*)\2~i',
            static fn(array $attribute): string => $attribute[1] . '=' . $attribute[2] . branch_url($attribute[3]) . $attribute[2], $tag[0]);
    }, $html) ?? $html;
}

function render_branch_selector(bool $admin = false): void
{
    $destination = $admin ? '/admin' : '/rooms';
    $branches = [1, 2];
    if ($admin) {
        $account = current_account();
        if ($account !== null && empty($account['root'])) {
            $branches = [(int)$account['branch_id']];
        }
    }
    ?>
    <nav class="branch-selector" aria-label="Chọn chi nhánh">
        <span class="w-full font-semibold sm:w-auto">Chi nhánh</span>
        <?php foreach ($branches as $branch): ?>
            <a data-branch-switch href="<?= e(branch_url($destination, $branch)) ?>"
                class="branch-link"
                <?= branch_id() === $branch ? 'aria-current="true"' : '' ?>>Chi nhánh <?= $branch ?></a>
        <?php endforeach ?>
    </nav>
    <?php
}

function render_branch_empty(): void
{
    ?>
    <section class="branch-empty mx-auto my-10 max-w-2xl rounded-2xl border border-[#e3d5c5] bg-[#fffaf2] p-6 text-[#49382c] sm:p-10">
        <p class="text-sm font-semibold text-[#79543e]">Mơ Home · <?= e(branch_label()) ?></p>
        <h1>Chưa có phòng để đặt</h1>
        <p class="mt-4 leading-7">Chi nhánh đang được chuẩn bị. Mơ sẽ cập nhật phòng và bảng giá tại đây khi sẵn sàng đón bạn.</p>
        <a data-branch-switch href="/rooms" class="mt-6 inline-flex min-h-11 items-center rounded-lg bg-[#79543e] px-5 py-3 font-medium text-white! hover:bg-[#65432e] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e]">Xem phòng chi nhánh 1</a>
    </section>
    <?php
}
