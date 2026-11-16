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
        'machine'        => (string) (gethostname() ?: ''),
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

/* ------------------------------------------------------------------ merge
 * The weekly teacher hand-in.
 *
 * A teacher's laptop is its own copy of the system: same students, same
 * schedule, its own auto-increment IDs. So nothing here can match on IDs —
 * every row is recognised by what it actually is (student number, class slot,
 * session date + student), and the file's IDs are translated into this site's
 * IDs as it goes.
 *
 * Merge only ADDS: nothing is deleted, nothing that already exists is
 * overwritten. A row that is already here is counted as "already here"; one
 * that differs in a way that matters (a name, a room, a status) is counted as
 * a conflict and this site's version is kept.
 * ------------------------------------------------------------------------ */

function backup_merge(array $payload, bool $dryRun = false): array
{
    $db   = db();
    $data = $payload['tables'] ?? [];

    // Audit history is this machine's own story — merging copies of it only
    // duplicates the same lines. Replace mode still carries it over.
    $tables = array_values(array_filter(backup_tables(), static fn($t) => $t !== 'audit_logs'));

    $stats = [];
    foreach ($tables as $table) {
        $stats[$table] = ['new' => 0, 'have' => 0, 'conflict' => 0, 'skipped' => 0];
    }
    $examples = [];

    // Columns needed to recognise a row. Keeping this list tight means a big
    // attendance table never has to sit in memory with every column.
    $keyColumns = [
        'roles'               => ['name'],
        'users'               => ['username'],
        'teachers'            => ['user_id'],
        'subjects'            => ['code', 'name'],
        'sections'            => ['name', 'grade_level', 'track_strand'],
        'school_years'        => ['name'],
        'semesters'           => ['school_year_id', 'name'],
        'students'            => ['student_id', 'first_name', 'last_name'],
        'schedule_entries'    => ['teacher_id', 'subject_id', 'section_id', 'day_of_week', 'start_time', 'end_time', 'room'],
        'attendance_sessions' => ['schedule_entry_id', 'attendance_date'],
        'attendance_records'  => ['attendance_session_id', 'student_id', 'status'],
        'sms_logs'            => ['student_id', 'type', 'sms_date', 'channel', 'dedup_key'],
    ];

    $norm = static fn($v) => mb_strtolower(trim((string) $v));

    $keyOf = [
        'roles'               => static fn(array $r) => $norm($r['name'] ?? ''),
        'users'               => static fn(array $r) => $norm($r['username'] ?? ''),
        'teachers'            => static fn(array $r) => 'u' . (int) ($r['user_id'] ?? 0),
        'subjects'            => static fn(array $r) => $norm($r['code'] ?? '') !== ''
            ? 'c:' . $norm($r['code'])
            : 'n:' . $norm($r['name'] ?? ''),
        'sections'            => static fn(array $r) => $norm($r['name'] ?? '') . '|' . $norm($r['grade_level'] ?? ''),
        'school_years'        => static fn(array $r) => $norm($r['name'] ?? ''),
        'semesters'           => static fn(array $r) => (int) ($r['school_year_id'] ?? 0) . '|' . $norm($r['name'] ?? ''),
        'students'            => static fn(array $r) => $norm($r['student_id'] ?? ''),
        'schedule_entries'    => static fn(array $r) => implode('|', [
            (int) ($r['teacher_id'] ?? 0), (int) ($r['subject_id'] ?? 0), (int) ($r['section_id'] ?? 0),
            $norm($r['day_of_week'] ?? ''), substr((string) ($r['start_time'] ?? ''), 0, 5),
            substr((string) ($r['end_time'] ?? ''), 0, 5),
        ]),
        'attendance_sessions' => static fn(array $r) => (int) ($r['schedule_entry_id'] ?? 0) . '|' . (string) ($r['attendance_date'] ?? ''),
        'attendance_records'  => static fn(array $r) => (int) ($r['attendance_session_id'] ?? 0) . '|' . (int) ($r['student_id'] ?? 0),
        'sms_logs'            => static fn(array $r) => implode('|', [
            (int) ($r['student_id'] ?? 0), (string) ($r['type'] ?? ''), (string) ($r['sms_date'] ?? ''),
            (string) ($r['channel'] ?? ''), (int) ($r['dedup_key'] ?? 0),
        ]),
    ];

    // What this site already has, keyed the same way.
    $existing = [];
    foreach ($tables as $table) {
        $cols = $keyColumns[$table] ?? [];
        $existing[$table] = [];
        if (!$cols) continue;
        $sql = "SELECT id, `" . implode('`,`', $cols) . "` FROM `$table`";
        foreach ($db->query($sql)->fetchAll() as $row) {
            $existing[$table][$keyOf[$table]($row)] = $row;
        }
    }

    $targetColumns = [];
    foreach ($tables as $table) {
        $targetColumns[$table] = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    }

    $map = array_fill_keys(backup_tables(), []);
    $mapId = static function (string $table, $value) use (&$map): ?int {
        if ($value === null || $value === '') return null;
        return $map[$table][(int) $value] ?? null;
    };

    $db->beginTransaction();
    try {
        foreach ($tables as $table) {
            $rows = $data[$table] ?? null;
            if (!is_array($rows) || !$rows) continue;

            // Columns this file and this database share — never `id`.
            $columns = array_values(array_diff(
                array_intersect($targetColumns[$table], array_keys((array) $rows[0])),
                ['id']
            ));
            if (!$columns) continue;

            $insert = $db->prepare(
                "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES ("
                . implode(',', array_fill(0, count($columns), '?')) . ")"
            );

            foreach ($rows as $row) {
                $sourceId = (int) ($row['id'] ?? 0);
                $mapped   = $row;
                $skip     = false;

                switch ($table) {
                    case 'users':
                        $mapped['role_id'] = $mapId('roles', $row['role_id'] ?? null);
                        $skip = $mapped['role_id'] === null;
                        break;
                    case 'teachers':
                        $mapped['user_id'] = $mapId('users', $row['user_id'] ?? null);
                        $skip = $mapped['user_id'] === null;
                        break;
                    case 'semesters':
                        $mapped['school_year_id'] = $mapId('school_years', $row['school_year_id'] ?? null);
                        $skip = $mapped['school_year_id'] === null;
                        break;
                    case 'students':
                        $section = $mapId('sections', $row['section_id'] ?? null);
                        if (!empty($row['section_id']) && $section === null) { $skip = true; break; }
                        $mapped['section_id'] = $section;
                        break;
                    case 'schedule_entries':
                        $mapped['teacher_id']  = $mapId('teachers', $row['teacher_id'] ?? null);
                        $mapped['subject_id']  = $mapId('subjects', $row['subject_id'] ?? null);
                        $mapped['section_id']  = $mapId('sections', $row['section_id'] ?? null);
                        $mapped['semester_id'] = $mapId('semesters', $row['semester_id'] ?? null);
                        $skip = in_array(null, [$mapped['teacher_id'], $mapped['subject_id'], $mapped['section_id'], $mapped['semester_id']], true);
                        break;
                    case 'attendance_sessions':
                        $mapped['schedule_entry_id'] = $mapId('schedule_entries', $row['schedule_entry_id'] ?? null);
                        $mapped['teacher_id']        = $mapId('teachers', $row['teacher_id'] ?? null);
                        $skip = in_array(null, [$mapped['schedule_entry_id'], $mapped['teacher_id']], true);
                        break;
                    case 'attendance_records':
                        $mapped['attendance_session_id'] = $mapId('attendance_sessions', $row['attendance_session_id'] ?? null);
                        $mapped['student_id']            = $mapId('students', $row['student_id'] ?? null);
                        $skip = in_array(null, [$mapped['attendance_session_id'], $mapped['student_id']], true);
                        break;
                    case 'sms_logs':
                        $mapped['student_id']           = $mapId('students', $row['student_id'] ?? null);
                        $mapped['attendance_record_id'] = $mapId('attendance_records', $row['attendance_record_id'] ?? null);
                        $skip = $mapped['student_id'] === null;
                        break;
                }

                if ($skip) {
                    $stats[$table]['skipped']++;
                    continue;
                }

                $key = $keyOf[$table]($mapped);

                if (isset($existing[$table][$key])) {
                    $have = $existing[$table][$key];
                    $map[$table][$sourceId] = (int) $have['id'];
                    $stats[$table]['have']++;

                    $conflict = false;
                    if ($table === 'students') {
                        $conflict = $norm($have['first_name']) !== $norm($mapped['first_name'] ?? '')
                            || $norm($have['last_name']) !== $norm($mapped['last_name'] ?? '');
                    } elseif ($table === 'sections') {
                        $conflict = $norm($have['track_strand'] ?? '') !== $norm($mapped['track_strand'] ?? '');
                    } elseif ($table === 'schedule_entries') {
                        $conflict = $norm($have['room'] ?? '') !== $norm($mapped['room'] ?? '');
                    } elseif ($table === 'attendance_records') {
                        $conflict = ($have['status'] ?? '') !== ($mapped['status'] ?? '');
                    }
                    if ($conflict) {
                        $stats[$table]['conflict']++;
                        if (count($examples) < 5) {
                            $examples[] = $table . ': ' . $key;
                        }
                    }
                    continue;
                }

                $values = [];
                foreach ($columns as $column) {
                    $values[] = array_key_exists($column, $mapped) ? $mapped[$column] : null;
                }
                $insert->execute($values);

                $newId = (int) $db->lastInsertId();
                $map[$table][$sourceId] = $newId;
                $existing[$table][$key] = ['id' => $newId] + array_intersect_key($mapped, array_flip($keyColumns[$table] ?? []));
                $stats[$table]['new']++;
            }
        }

        if ($dryRun) {
            $db->rollBack();
        } else {
            $db->commit();
        }
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    return [
        'stats'    => $stats,
        'examples' => $examples,
        'dry_run'  => $dryRun,
    ];
}
