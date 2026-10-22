<?php

declare(strict_types=1);

// Install beside .env, never inside public_html. No HTTP deployment endpoint.
function agent_target_path(string $source): ?string
{
    if (str_contains($source, '..') || str_contains($source, '\\') || str_contains($source, "\0")) return null;
    if (preg_match('#^src/[A-Za-z0-9_.-]+\.php$#D', $source)) return $source;
    if (preg_match('#^database/migrations/[A-Za-z0-9_.-]+\.sql$#D', $source)) return $source;
    if (preg_match('#^public/(?:index\.php|(?:favicon|apple-touch-icon)\.svg|assets/[A-Za-z0-9_/-]+\.(?:css|js)|images/[A-Za-z0-9_./-]+\.(?:jpg|jpeg|png|webp|svg))$#Di', $source)) {
        return 'public_html/' . substr($source, 7);
    }
    return null;
}

function agent_eligible_run(array $run): bool
{
    return ($run['name'] ?? '') === 'Prepare PHP deployment'
        && ($run['head_branch'] ?? '') === 'main'
        && ($run['status'] ?? '') === 'completed'
        && ($run['conclusion'] ?? '') === 'success'
        && in_array($run['event'] ?? '', ['push', 'workflow_dispatch'], true)
        && preg_match('/^[a-f0-9]{40}$/D', (string)($run['head_sha'] ?? '')) === 1
        && (int)($run['id'] ?? 0) > 0;
}

function agent_read_archive(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('Invalid repository ZIP.');
    $files = [];
    $total = 0;
    $prefix = null;
    try {
        if ($zip->numFiles > 10000) throw new RuntimeException('Too many archive entries.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) throw new RuntimeException('Cannot read archive entry.');
            $name = (string)$stat['name'];
            if (!preg_match('#^([A-Za-z0-9_.-]+)/(.+)$#D', $name, $match)) continue;
            $prefix ??= $match[1];
            if ($prefix !== $match[1]) throw new RuntimeException('Multiple archive roots.');
            $target = agent_target_path($match[2]);
            if ($target === null) continue;
            $zip->getExternalAttributesIndex($i, $os, $attributes);
            if ((($attributes >> 16) & 0170000) === 0120000) throw new RuntimeException('Archive symlinks are forbidden.');
            if (isset($files[$target]) || (int)$stat['size'] > 10 * 1024 * 1024) throw new RuntimeException('Duplicate or oversized runtime file.');
            $total += (int)$stat['size'];
            if ($total > 64 * 1024 * 1024) throw new RuntimeException('Runtime package too large.');
            $content = $zip->getFromIndex($i);
            if ($content === false || strlen($content) !== (int)$stat['size']) throw new RuntimeException('Incomplete runtime file.');
            if (str_ends_with($target, '.php')) {
                try {
                    token_get_all($content, TOKEN_PARSE);
                } catch (ParseError) {
                    throw new RuntimeException('PHP syntax validation failed.');
                }
            }
            $files[$target] = $content;
        }
    } finally {
        $zip->close();
    }
    foreach (['public_html/index.php', 'src/bootstrap.php', 'public_html/assets/tailwind.css'] as $required) {
        if (!isset($files[$required]) || $files[$required] === '') throw new RuntimeException('Required runtime file is missing.');
    }
    ksort($files);
    return $files;
}

function agent_safe_target(string $root, string $relative): string
{
    $source = str_starts_with($relative, 'public_html/') ? 'public/' . substr($relative, 12) : $relative;
    if (agent_target_path($source) !== $relative) throw new RuntimeException('Target is outside runtime allowlist.');
    $current = $root;
    foreach (explode('/', $relative) as $segment) {
        $current .= '/' . $segment;
        if (is_link($current)) throw new RuntimeException('Target path contains a symlink.');
    }
    return $current;
}

function agent_atomic_write(string $target, string $content, int $mode = 0644): void
{
    $directoryMode = $mode === 0600 ? 0700 : 0755;
    $missing = [];
    for ($directory = dirname($target); !is_dir($directory); $directory = dirname($directory)) {
        if (is_link($directory) || dirname($directory) === $directory) {
            throw new RuntimeException('Cannot create target directory.');
        }
        $missing[] = $directory;
    }
    foreach (array_reverse($missing) as $directory) {
        if (!mkdir($directory, $directoryMode) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create target directory.');
        }
        // The CLI uses a private umask; Apache still needs to traverse runtime assets.
        if (!chmod($directory, $directoryMode)) throw new RuntimeException('Cannot set directory permissions.');
    }
    if (is_link($target)) throw new RuntimeException('Cannot replace a symlink.');
    $temporary = $target . '.deploy-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot write staged file.');
    }
    chmod($temporary, $mode);
    if (!rename($temporary, $target)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot publish staged file.');
    }
    if (function_exists('opcache_invalidate') && str_ends_with($target, '.php')) @opcache_invalidate($target, true);
}

function agent_publish(string $root, array $files, string $backup, callable $migrate): int
{
    $targets = [];
    foreach ($files as $path => $content) {
        $target = agent_safe_target($root, $path);
        if (is_file($target) && hash_file('sha256', $target) === hash('sha256', $content)) continue;
        if (file_exists($target) && !is_file($target)) throw new RuntimeException('Target is not a regular file.');
        $targets[$path] = $target;
    }
    // Back up every changed file before publishing the first file.
    $originals = [];
    foreach ($targets as $path => $target) {
        $originals[$path] = is_file($target) ? file_get_contents($target) : null;
        if ($originals[$path] === false) throw new RuntimeException('Cannot read existing file.');
        if ($originals[$path] !== null) agent_atomic_write($backup . '/' . $path, $originals[$path], 0600);
    }
    $published = [];
    try {
        foreach ($targets as $path => $target) {
            // Recheck just before each write; never follow changed path symlinks.
            agent_safe_target($root, $path);
            agent_atomic_write($target, $files[$path]);
            $published[] = $path;
        }
        $migrate();
    } catch (Throwable $e) {
        $restoreFailed = false;
        foreach (array_reverse($published) as $path) {
            try {
                $target = agent_safe_target($root, $path);
                if ($originals[$path] === null) {
                    if (is_file($target) && !unlink($target)) throw new RuntimeException('Cannot remove new file.');
                } else {
                    agent_atomic_write($target, $originals[$path]);
                }
            } catch (Throwable) {
                $restoreFailed = true;
            }
        }
        if ($restoreFailed) throw new RuntimeException('Deployment failed; file restore incomplete. Use private backup and inspect database.');
        throw $e;
    }
    return count($targets);
}

function agent_github_get(string $url, string $token, int $limit = 2097152): array
{
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'api.github.com') {
        throw new RuntimeException('GitHub API URL rejected.');
    }
    $location = '';
    $body = '';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $token, 'X-GitHub-Api-Version: 2022-11-28'],
        CURLOPT_USERAGENT => 'MoHome-PullDeploy/1.0',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
            if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($ok === false || !in_array($status, [200, 302], true)) throw new RuntimeException('GitHub API request failed (HTTP ' . $status . '). Check token permissions, quota and network.');
    return ['status' => $status, 'body' => $body, 'location' => $location];
}

function agent_download_archive(string $url, string $file): void
{
    // Never forward the GitHub bearer token to the archive download host.
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'codeload.github.com'
        || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PORT) !== null) {
        throw new RuntimeException('Archive download URL rejected.');
    }
    $stream = fopen($file, 'xb');
    if ($stream === false) throw new RuntimeException('Cannot create archive staging file.');
    $bytes = 0;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_USERAGENT => 'MoHome-PullDeploy/1.0', CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($stream, &$bytes): int {
            $bytes += strlen($chunk);
            if ($bytes > 50 * 1024 * 1024) return 0;
            return (int)fwrite($stream, $chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    fclose($stream);
    chmod($file, 0600);
    if ($ok === false || $status !== 200) throw new RuntimeException('Archive download failed.');
}

function agent_main(array $arguments): void
{
    $mode = $arguments[1] ?? '--check';
    if (!in_array($mode, ['--check', '--plan', '--run'], true)) throw new RuntimeException('Use --check, --plan or --run.');
    foreach (['curl', 'zip', 'pdo_mysql'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException('Required PHP extension is missing: ' . $extension);
    }
    $root = __DIR__;
    $configFile = $root . '/deploy-agent.ini';
    if (!is_file($configFile)) throw new RuntimeException('Create deploy-agent.ini beside this agent, outside public_html.');
    $config = parse_ini_file($configFile, false, INI_SCANNER_RAW);
    if (!is_array($config) || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', (string)($config['REPOSITORY'] ?? ''))) {
        throw new RuntimeException('Invalid agent repository configuration.');
    }
    $token = trim((string)($config['GITHUB_TOKEN'] ?? ''));
    if ($token === '' || preg_match('/[\r\n]/', $token)) throw new RuntimeException('GitHub read-only token is missing or invalid.');
    if (!is_file($root . '/.env') || !is_dir($root . '/public_html') || !is_writable($root)) throw new RuntimeException('Agent must be installed in the domain root beside .env and public_html.');
    if ($mode === '--check') {
        echo "PHP prerequisites and local configuration: OK. No network or website changes.\n";
        return;
    }
    if ($mode === '--run' && !filter_var($config['ENABLED'] ?? 'false', FILTER_VALIDATE_BOOL)) {
        throw new RuntimeException('Agent is disabled. Run --plan before setting ENABLED=true.');
    }
    $private = $root . '/.deploy-agent';
    if (is_link($private) || (!is_dir($private) && !mkdir($private, 0700))) throw new RuntimeException('Cannot create private agent storage.');
    $lock = fopen($private . '/agent.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $archive = $private . '/archive-' . bin2hex(random_bytes(8)) . '.zip';
    try {
        $stateFile = $private . '/state.json';
        $state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : [];
        if (!is_array($state)) throw new RuntimeException('Agent state is corrupt; inspect before retrying.');
        $api = 'https://api.github.com/repos/' . $config['REPOSITORY'];
        $response = agent_github_get($api . '/actions/workflows/deploy-directadmin.yml/runs?branch=main&per_page=1', $token);
        $runs = json_decode($response['body'], true);
        $run = $runs['workflow_runs'][0] ?? [];
        if (!agent_eligible_run($run)) return; // Never fall back to an older passing run.
        $sha = $run['head_sha'];
        if (($state['sha'] ?? '') === $sha || (int)($state['run_id'] ?? 0) > (int)$run['id']) return;
        $attempts = ($state['attempt_sha'] ?? '') === $sha ? (int)($state['attempts'] ?? 0) : 0;
        if ($mode === '--run') {
            if ($attempts >= 3) throw new RuntimeException('Three failed attempts for this commit. Inspect logs and database before manually retrying.');
            $state['attempt_sha'] = $sha;
            $state['attempts'] = $attempts + 1;
            agent_atomic_write($stateFile, json_encode($state, JSON_THROW_ON_ERROR), 0600);
        }
        $response = agent_github_get($api . '/zipball/' . $sha, $token);
        if ($response['status'] !== 302) throw new RuntimeException('Expected GitHub archive redirect.');
        agent_download_archive($response['location'], $archive);
        $files = agent_read_archive($archive);
        if ($mode === '--plan') {
            $changes = 0;
            foreach ($files as $path => $content) {
                $target = agent_safe_target($root, $path);
                if (!is_file($target) || hash_file('sha256', $target) !== hash('sha256', $content)) $changes++;
            }
            echo 'Validated commit ' . substr($sha, 0, 12) . ': ' . $changes . " changed files. Website and database unchanged.\n";
            return;
        }
        $backup = $private . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $count = agent_publish($root, $files, $backup, static function () use ($root): void {
            require_once $root . '/src/bootstrap.php';
            require_once $root . '/src/migrate.php';
            run_migrations();
        });
        $state = ['sha' => $sha, 'run_id' => (int)$run['id'], 'deployed_at' => gmdate('c'), 'backup' => basename($backup)];
        agent_atomic_write($stateFile, json_encode($state, JSON_THROW_ON_ERROR), 0600);
        echo 'Deployed ' . substr($sha, 0, 12) . ': ' . $count . " changed files; migrations completed.\n";
    } finally {
        if (is_file($archive)) unlink($archive);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    umask(0077);
    try {
        agent_main($argv);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Deploy agent stopped: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
