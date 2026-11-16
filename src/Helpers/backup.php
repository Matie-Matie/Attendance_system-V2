<?php
/**
 * Backup helpers — move a whole school between machines.
 *
 * The school runs SCPLS on its own PC and also online. Export writes every
 * table (except `settings`, which is per-machine) into one gzipped JSON file;
 * import replaces the data on the target after writing a safety copy of what
 * was there. Row IDs are kept, so students, schedules and attendance stay
 * linked exactly as they were.
 */

/**
 * Tables in dependency order — parents before children.
 * `settings` is deliberately absent: it holds this machine's schema version
 * and background-task clock, which must not travel.
 */
function backup_tables(): array
{
    return [
        'roles', 'users', 'teachers', 'subjects', 'sections', 'school_years', 'semesters',
        'students', 'schedule_entries', 'attendance_sessions', 'attendance_records',
        'sms_logs', 'audit_logs',
    ];
}

function backup_dir(): string
{
    $dir = BASE_PATH . '/storage/backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/**
 * Write every table to storage/backups as gzipped JSON.
 * Rows are streamed in pages of 1000 so a big attendance table never has to
 * sit in memory at once.
 *
 * @return array{path:string, name:string, counts:array<string,int>, bytes:int, generated:string}
 */
function backup_export(?string $path = null): array
{
    $path = $path ?: backup_dir() . '/scpls-backup-' . date('Ymd-His') . '.json.gz';
    $gzip = function_exists('gzopen');

    $fh = $gzip ? gzopen($path, 'wb9') : fopen($path, 'wb');
    if (!$fh) {
        throw new RuntimeException('Could not create the backup file.');
    }

    $write = function (string $chunk) use ($fh, $gzip): void {
        $gzip ? gzwrite($fh, $chunk) : fwrite($fh, $chunk);
    };

    $counts = [];
    foreach (backup_tables() as $table) {
        $counts[$table] = (int) db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }

    $meta = [
        'app'            => 'SCPLS',
        'version'        => (string) config('version'),
        'school'         => (string) config('school_name'),
        'generated'      => date('c'),
        'schema_version' => (string) setting_get('schema_version', '1'),
        'counts'         => $counts,
    ];

    $write('{"meta":' . json_encode($meta, JSON_UNESCAPED_UNICODE) . ',"tables":{');

    $firstTable = true;
    foreach (backup_tables() as $table) {
        $write(($firstTable ? '' : ',') . json_encode($table) . ':[');
        $firstTable = false;

        $lastId = 0;
        $firstRow = true;
        do {
            $stmt = db()->prepare("SELECT * FROM `$table` WHERE id > ? ORDER BY id LIMIT 1000");
            $stmt->execute([$lastId]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $write(($firstRow ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                $firstRow = false;
                $lastId = (int) $row['id'];
            }
        } while (count($rows) === 1000);

        $write(']');
    }

    $write('}}');
    $gzip ? gzclose($fh) : fclose($fh);

    return [
        'path'      => $path,
        'name'      => basename($path),
        'counts'    => $counts,
        'bytes'     => (int) @filesize($path),
        'generated' => $meta['generated'],
    ];
}

/**
 * Read an exported file (plain .json or .json.gz) and validate its shape.
 */
function backup_read_payload(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('The backup file could not be read.');
    }

    if (strncmp($raw, "\x1f\x8b", 2) === 0) {
        $raw = @gzdecode($raw);
        if ($raw === false) {
            throw new RuntimeException('The .gz backup is damaged.');
        }
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
        throw new RuntimeException('That file is not an SCPLS backup.');
    }

    return $data;
}

/**
 * Row counts inside a payload, limited to the tables this app knows.
 *
 * @return array<string,int>
 */
function backup_payload_counts(array $payload): array
{
    $counts = [];
    foreach (backup_tables() as $table) {
        if (isset($payload['tables'][$table]) && is_array($payload['tables'][$table])) {
            $counts[$table] = count($payload['tables'][$table]);
        }
    }
    return $counts;
}

/**
 * Replace this site's data with the payload.
 *
 * - a safety export of the current data is written first
 * - everything happens in one transaction; FOREIGN_KEY_CHECKS is only off
 *   for the duration of this connection
 * - tables missing from the file are left untouched
 * - row IDs are preserved and only columns that exist on both sides are copied
 *
 * @return array{counts:array<string,int>, safety:?string}
 */
function backup_import(array $payload, bool $safety = true): array
{
    $safetyPath = null;
    if ($safety) {
        try {
            $safetyPath = backup_export()['path'];
        } catch (\Throwable $e) {
            error_log('SCPLS import safety export: ' . $e->getMessage());
        }
    }

    $db = db();
    $tables = backup_tables();

    $targetColumns = [];
    foreach ($tables as $table) {
        $targetColumns[$table] = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    }

    $present = [];
    foreach ($tables as $table) {
        if (isset($payload['tables'][$table]) && is_array($payload['tables'][$table])) {
            $present[] = $table;
        }
    }
    if (!$present) {
        throw new RuntimeException('The backup contains no tables this app can import.');
    }

    $counts = array_fill_keys($present, 0);

    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $db->beginTransaction();

        // Children first, so nothing is orphaned mid-way.
        foreach (array_reverse($present) as $table) {
            $db->exec("DELETE FROM `$table`");
        }

        foreach ($present as $table) {
            $rows = $payload['tables'][$table];
            if (!$rows) {
                continue;
            }

            $sourceColumns = array_keys((array) $rows[0]);
            $columns = array_values(array_intersect($targetColumns[$table], $sourceColumns));
            if (!$columns) {
                continue;
            }

            $columnList = '`' . implode('`,`', $columns) . '`';
            foreach (array_chunk($rows, 200) as $chunk) {
                $placeholders = [];
                $values = [];
                foreach ($chunk as $row) {
                    $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                    foreach ($columns as $column) {
                        $values[] = array_key_exists($column, $row) ? $row[$column] : null;
                    }
                }
                $stmt = $db->prepare("INSERT INTO `$table` ($columnList) VALUES " . implode(',', $placeholders));
                $stmt->execute($values);
                $counts[$table] += count($chunk);
            }
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        throw $e;
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');

    return ['counts' => $counts, 'safety' => $safetyPath];
}
