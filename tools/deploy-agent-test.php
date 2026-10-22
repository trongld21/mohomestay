<?php

declare(strict_types=1);

function agent_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$library = $argv[1] ?? dirname(__DIR__) . '/deploy-agent.php';
agent_expect(is_file($library), 'Missing pull deployment agent');
require $library;
foreach (['public/index.php' => 'public_html/index.php', 'src/mail.php' => 'src/mail.php', 'database/migrations/005_booking_access_codes.sql' => 'database/migrations/005_booking_access_codes.sql'] as $source => $target) {
    agent_expect(agent_target_path($source) === $target, 'Map runtime file: ' . $source);
}
foreach (['.env', 'public/uploads/rooms/a.jpg', 'public/.htaccess', 'public/deploy-hook.php', 'src/styles/tailwind.css', 'public/p.zip', '../src/mail.php', 'public/assets/../index.php', 'tools/set-ttlock-password.ps1'] as $source) {
    agent_expect(agent_target_path($source) === null, 'Protect non-deployable path: ' . $source);
}
$run = ['name' => 'Prepare PHP deployment', 'head_branch' => 'main', 'status' => 'completed', 'conclusion' => 'success', 'head_sha' => str_repeat('a', 40), 'id' => 123, 'event' => 'push'];
agent_expect(agent_eligible_run($run), 'Successful main run accepted');
foreach ([['status' => 'in_progress'], ['conclusion' => 'failure'], ['head_branch' => 'other'], ['name' => 'Old deploy'], ['head_sha' => '../bad'], ['event' => 'pull_request']] as $change) {
    agent_expect(!agent_eligible_run(array_replace($run, $change)), 'Reject untrusted or untested run');
}
$directory = sys_get_temp_dir() . '/mo-agent-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$archive = $directory . '/source.zip';
$zip = new ZipArchive();
$zip->open($archive, ZipArchive::CREATE);
$zip->addFromString('repo-sha/public/index.php', '<?php echo "new";');
$zip->addFromString('repo-sha/src/bootstrap.php', '<?php function example() {}');
$zip->addFromString('repo-sha/public/assets/tailwind.css', 'body{}');
$zip->addFromString('repo-sha/.env', 'SECRET=never-copy');
$zip->addFromString('repo-sha/public/uploads/rooms/photo.jpg', 'user-image');
$zip->close();
$files = agent_read_archive($archive);
agent_expect(count($files) === 3 && !isset($files['.env']), 'Select only allowed runtime archive contents');
mkdir($directory . '/site/public_html/uploads/rooms', 0700, true);
file_put_contents($directory . '/site/.env', 'keep-secret');
file_put_contents($directory . '/site/public_html/uploads/rooms/photo.jpg', 'keep-photo');
file_put_contents($directory . '/site/public_html/index.php', 'old-content');
$previousMask = umask(0077);
if (PHP_OS_FAMILY !== 'Windows') {
    agent_atomic_write($directory . '/mode-test/public/assets/app.js', 'ok');
    agent_atomic_write($directory . '/mode-test/private/backups/app.js', 'ok', 0600);
    agent_expect((fileperms($directory . '/mode-test/public/assets') & 0777) === 0755, 'Apache can traverse newly created runtime directories under private umask');
    agent_expect((fileperms($directory . '/mode-test/private/backups') & 0777) === 0700, 'Backup directories remain private');
}
$backup = $directory . '/backup';
try {
    agent_publish($directory . '/site', $files, $backup, static function (): void { throw new RuntimeException('Migration failed'); });
    throw new LogicException('Publication failure should propagate');
} catch (RuntimeException $e) {
    agent_expect($e->getMessage() === 'Migration failed', 'Propagate original migration failure');
}
agent_expect(file_get_contents($directory . '/site/public_html/index.php') === 'old-content', 'Restore previous file after failure');
agent_expect(!is_file($directory . '/site/src/bootstrap.php'), 'Remove only newly created runtime files on rollback');
agent_publish($directory . '/site', $files, $directory . '/backup2', static function (): void {});
agent_expect(file_get_contents($directory . '/site/public_html/index.php') === '<?php echo "new";', 'Publish valid update');
agent_expect(file_get_contents($directory . '/site/.env') === 'keep-secret', 'Never overwrite .env');
agent_expect(file_get_contents($directory . '/site/public_html/uploads/rooms/photo.jpg') === 'keep-photo', 'Never overwrite administrator uploads');
$zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('repo-sha/public/index.php', '<?php broken syntax here');
$zip->close();
try {
    agent_read_archive($archive);
    throw new LogicException('Invalid PHP accepted');
} catch (ParseError|RuntimeException $e) {
}
$unchanged = agent_publish($directory . '/site', $files, $directory . '/backup3', static function (): void {});
agent_expect($unchanged === 0, 'Unchanged runtime files are not rewritten');
foreach (['https://example.com/archive.zip', 'http://codeload.github.com/file', 'https://codeload.github.com.evil.test/file'] as $url) {
    $rejected = false;
    try {
        agent_download_archive($url, $directory . '/never-created.zip');
    } catch (RuntimeException) {
        $rejected = true;
    }
    agent_expect($rejected && !is_file($directory . '/never-created.zip'), 'Reject archive redirect to an untrusted destination before any download');
}
$zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('repo-sha/public/index.php', '<?php echo 1;');
$zip->setExternalAttributesName('repo-sha/public/index.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
$zip->addFromString('repo-sha/src/bootstrap.php', '<?php function example() {}');
$zip->addFromString('repo-sha/public/assets/tailwind.css', 'body{}');
$zip->close();
$rejected = false;
try {
    agent_read_archive($archive);
} catch (RuntimeException $e) {
    $rejected = $e->getMessage() === 'Archive symlinks are forbidden.';
}
agent_expect($rejected, 'Reject runtime symlink from archive');
umask($previousMask);
echo "Pull deploy agent tests: OK\n";
