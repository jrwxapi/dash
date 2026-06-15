<?php
/**
 * Poller dispatcher — run every minute from cron:
 *   * * * * * php /path/to/dash/cron/poll.php >> /path/to/dash/logs/poll.log 2>&1
 *
 * Each poller runs when its interval (includes/config.php POLL_INTERVALS) has
 * elapsed since its last run. Run one poller immediately regardless of
 * schedule with:  php cron/poll.php <name>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/http.php';

function plog(string $name, string $msg): void {
    echo gmdate('Y-m-d H:i:s') . " [$name] $msg\n";
}

$forced = $argv[1] ?? null;
if ($forced !== null && !isset(POLL_INTERVALS[$forced])) {
    fwrite(STDERR, "Unknown poller '$forced'. Known: " . implode(', ', array_keys(POLL_INTERVALS)) . "\n");
    exit(1);
}

kv_purge_expired();

foreach (POLL_INTERVALS as $name => $default) {
    if ($forced !== null && $name !== $forced) continue;

    // Admin can override any poller's cadence via the 'poll_interval_<name>'
    // setting; fall back to the config.php default for blank/too-small values.
    $interval = (int) setting("poll_interval_$name", (string)$default);
    if ($interval < 30) $interval = $default;

    $meta = kv_get("poll:last:$name");
    $due  = $forced !== null || !$meta || (time() - ($meta['ran_at'] ?? 0)) >= $interval;
    if (!$due) continue;

    $start = microtime(true);
    try {
        require_once __DIR__ . "/pollers/$name.php";
        $status = call_user_func("poll_$name");
        $ok = true;
    } catch (Throwable $e) {
        $status = 'ERROR: ' . $e->getMessage();
        $ok = false;
    }
    $elapsed = round(microtime(true) - $start, 1);
    // Record the attempt either way so a failing poller retries on its normal
    // cadence instead of every minute (avoids hammering an upstream outage).
    kv_set("poll:last:$name", [
        'ran_at' => time(),
        'ok'     => $ok,
        'status' => $status,
        'took_s' => $elapsed,
    ]);
    plog($name, "$status ({$elapsed}s)");
}
