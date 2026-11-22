<?php

declare(strict_types=1);

// Keep existing cron commands valid. Run each branch in a separate PHP process
// so static repositories, integration clients and configuration cannot leak.
function dispatch_branch_workers(string $worker): void
{
    if (PHP_SAPI !== 'cli' || getenv('MO_BRANCH') !== false) {
        return;
    }
    if (!function_exists('proc_open')) {
        fwrite(STDERR, "proc_open is disabled. Configure one cron per branch using MO_BRANCH=1 and MO_BRANCH=2.\n");
        exit(1);
    }
    $failed = false;
    foreach ([1, 2] as $branch) {
        $environment = getenv();
        $environment['MO_BRANCH'] = (string)$branch;
        $command = [PHP_BINARY, $worker, ...array_slice($_SERVER['argv'] ?? [], 1)];
        echo 'Chi nhánh ' . $branch . ':' . PHP_EOL;
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $environment);
        if (!is_resource($process)) {
            $failed = true;
            continue;
        }
        $failed = proc_close($process) !== 0 || $failed;
    }
    exit($failed ? 1 : 0);
}
