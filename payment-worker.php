<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/payment_attempts.php';

$options = getopt('', ['once','watch','limit:','stop-file:']);
$watch = array_key_exists('watch',$options);
$limit = filter_var($options['limit'] ?? 50,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
if ($limit === false || ($watch && array_key_exists('once',$options))) {
    fwrite(STDERR,"Usage: php payment-worker.php [--once|--watch] [--limit=50] [--stop-file=PATH]\n");
    exit(2);
}
if ($watch && !isset($options['limit'])) $limit = PHP_INT_MAX;
$stopFile = $options['stop-file'] ?? null;
$stop = false;
$terminate = function () use (&$stop) { $stop = true; };
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,$terminate);
    pcntl_signal(SIGINT,$terminate);
}
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler($terminate);
}
$providerFor = 'queued_payment_provider';
$processed = 0;
try {
    while (!$stop && (!$stopFile || !is_file($stopFile)) && $processed < $limit) {
        $job = claim_payment_job($pdo);
        if (!$job) {
            if (!$watch) break;
            usleep(500000);
            if ($stopFile) clearstatcache(true,$stopFile);
            continue;
        }
        run_payment_job($pdo,$job,$providerFor);
        ++$processed;
        if ($stopFile) clearstatcache(true,$stopFile);
    }
} catch (Throwable $error) {
    fwrite(STDERR,"Payment worker stopped after a database or queue error. Restart the worker.\n");
    exit(1);
}
echo "Payment worker processed $processed jobs.\n";

