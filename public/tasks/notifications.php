<?php
/**
 * Notification task runner — backstop for the automatic in-app tick.
 *
 * Call from a scheduled job (e.g. InfinityFree cron, once or twice a day):
 *   https://scpls.example/tasks/notifications.php?key=SECRET&task=all
 *
 * task = absent   -> sweep + email parents of students absent from first class
 *        pending   -> retry any queued/failed emails
 *        all       -> both (default)
 *
 * Safe to call repeatedly; every mail is de-duplicated at the DB level.
 */
// Locate bootstrap.php regardless of dev (public/tasks/) vs flattened deploy (tasks/).
$root = __DIR__;
while (!is_file($root . '/bootstrap.php') && $root !== dirname($root)) {
    $root = dirname($root);
}
require_once $root . '/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

$key = (string) config('mail.task_key', '');
if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$task = $_GET['task'] ?? 'all';
$out = [];

if ($task === 'absent' || $task === 'all') {
    $out[] = 'absent sweep: ' . notify_sweep_absentees() . ' email(s) queued';
}
if ($task === 'pending' || $task === 'all') {
    $out[] = 'pending: ' . notify_send_pending() . ' email(s) sent';
}
if (!$out) {
    $out[] = 'unknown task';
}

// Keep the in-app throttle in sync so it does not immediately re-sweep.
setting_set('last_absent_sweep', (string) time());

echo implode("\n", $out) . "\n";
