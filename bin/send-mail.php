<?php
/**
 * Background mail sender (command line only).
 *
 * api/scan.php starts this as a separate process when the app runs on PHP's
 * built-in server (start.bat), so a scan answers instantly instead of waiting
 * 2-8+ seconds for Gmail. Usage: php bin/send-mail.php <sms_log id> [...]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

foreach (array_slice($argv, 1) as $arg) {
    $id = (int) $arg;
    if ($id <= 0) {
        continue;
    }
    try {
        notify_send($id);
    } catch (\Throwable $e) {
        error_log('SCPLS notify send failed (#' . $id . '): ' . $e->getMessage());
    }
}
