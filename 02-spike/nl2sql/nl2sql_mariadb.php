<?php
/**
 * nl2sql_mariadb.php — Natural Language to SQL (MariaDB) with OpenAI Responses API
 * PHP 8.1+ / MariaDB 10.5+ / Apache or PHP-FPM
 * - 보안 강화를 위해 모든 설정은 .env 로 이동 (.env → getenv → 기본값 순으로 로드)
 */

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

/* ---------- .env 로더 & 헬퍼 ---------- */
function loadEnvArray(string $path): array {
    // .env가 없으면 빈 배열 반환 (getenv 로 대체)
    if (!is_file($path)) return [];
    $arr = @parse_ini_file($path, false, INI_SCANNER_TYPED);
    return is_array($arr) ? $arr : [];
}
function env_val(array $env, string $key, mixed $default = null): mixed {
    // .env → getenv → default
    return $env[$key] ?? getenv($key) ?? $default;
}
function env_bool(array $env, string $key, bool $default = false): bool {
    $v = env_val($env, $key, null);
    if (is_bool($v)) return $v;
    if ($v === null) return $default;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','on','yes'], true) ? true
         : (in_array($s, ['0','false','off','no'], true) ? false : $default);
}
function env_int(array $env, string $key, int $default): int {
    $v = env_val($env, $key, null);
    return is_numeric($v) ? (int)$v : $default;
}
function html($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- 환경설정 로드 ---------- */
$ENV = loadEnvArray(__DIR__.'/.env');

$CFG = [
    // DB
    'db_host'   => env_val($ENV, 'DB_HOST', '127.0.0.1'),
    'db_port'   => env_int($ENV, 'DB_PORT', 3306),
    'db_name'   => env_val($ENV, 'DB_NAME', 'regulation'),
    'db_user'   => env_val($ENV, 'DB_USER', 'readonly_user'),
    'db_pass'   => env_val($ENV, 'DB_PASS', 'readonly_password'),
    'charset'   => env_val($ENV, 'DB_CHARSET', 'utf8mb4'),

    // OpenAI Responses API
    'openai_model'       => env_val($ENV, 'OPENAI_MODEL', 'gpt-4o-mini'),
    'openai_endpoint'    => env_val($ENV, 'OPENAI_ENDPOINT', 'https://api.openai.com/v1/responses'),
    'openai_temperature' => (float)env_val($ENV, 'OPENAI_TEMPERATURE', 0.1),

    // 생성/실행 제약
    'max_schema_tables'   => env_int($ENV, 'MAX_SCHEMA_TABLES', 30),
    'sample_rows_per_tbl' => env_int($ENV, 'SAMPLE_ROWS_PER_TBL', 0),
    'auto_limit_default'  => env_int($ENV, 'AUTO_LIMIT_DEFAULT', 200),
    'execution_enabled'   => env_bool($ENV, 'EXECUTION_ENABLED', false),

    // 로그/캐시
    'log_dir'            => env_val($ENV, 'LOG_DIR', __DIR__ . '/logs'),
    'log_file'           => null, // 아래에서 log_dir 기반으로 설정
    'schema_cache_dir'   => env_val($ENV, 'SCHEMA_CACHE_DIR', __DIR__ . '/schema_cache'),
    'schema_cache_file'  => null, // 아래에서 schema_cache_dir 기반
    'schema_cache_ttl'   => env_int($ENV, 'SCHEMA_CACHE_TTL', 3600),

    // UI
    'app_title' => env_val($ENV, 'APP_TITLE', '자연어 → SQL 생성기 (MariaDB + OpenAI)'),
];

$CFG['log_file'] = rtrim($CFG['log_dir'], '/').'/queries.log';
$CFG['schema_cache_file'] = rtrim($CFG['schema_cache_dir'], '/').'/schema.json';

/* ---------- DB 연결 ---------- */
function pdo(): PDO {
    static $pdo = null;
    global $CFG;
    if ($pdo) return $pdo;
    $dsn = "mysql:host={$CFG['db_host']};port={$CFG['db_port']};dbname={$CFG['db_name']};charset={$CFG['charset']}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $CFG['db_user'], $CFG['db_pass'], $options);
    $pdo->exec("SET SESSION SQL_SAFE_UPDATES=1");
    return $pdo;
}

/* ---------- 로깅 ---------- */
function ensureLogReady(): void {
    global $CFG;
    if (!is_dir($CFG['log_dir'])) @mkdir($CFG['log_dir'], 0775, true);
    if (!file_exists($CFG['log_file'])) {
        @touch($CFG['log_file']);
        @chmod($CFG['log_file'], 0664);
    }
}
function logQuery(array $entry): void {
    global $CFG;
    ensureLogReady();
    $line = [
        'ts'        => date('Y-m-d H:i:s'),
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua'        => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'action'    => $entry['action']   ?? '',
        'question'  => $entry['question'] ?? '',
        'sql'      => $entry['sql']      ?? '',
        'executed'  => (bool)($entry['executed'] ?? false),
        'row_count' => (int)($entry['row_count'] ?? 0),
        'error'     => $entry['error']    ?? '',
    ];
    $msg = implode("\t", [
        $line['ts'],
        $line['ip'],
        $line['action'],
        'executed=' . ($line['executed'] ? '1':'0'),
        'rows=' . $line['row_count'],
        'err=' . str_replace(["\n","\r","\t"], ' ', (string)$line['error']),
        'ua=' . str_replace(["\n","\r","\t"], ' ', $line['ua']),
        'Q=' . str_replace(["\n","\r","\t"], ' ', $line['question']),
        'SQL=' . str_replace(["\n","\r"], ' ', $line['sql']),
    ]) . PHP_EOL;
    $fh = @fopen($CFG['log_file'], 'ab');
    if ($fh) {
        @flock($fh, LOCK_EX);
        @fwrite($fh, $msg);
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

/* ---------- 스키마 캐시 ---------- */
function ensureSchemaCacheReady(): void {
    global $CFG;
    if (!is_dir($CFG['schema_cache_dir'])) @mkdir($CFG['schema_cache_dir'], 0775, true);
}
function loadSchemaCache(): ?array {
    global $CFG;
    if (!file_exists($CFG['schema_cache_file'])) return null;
    $mtime = @filemtime($CFG['schema_cache_file']);
    if (!$mtime) return null;
    $age = time() - $mtime;
    if ($age > $CFG['schema_cache_ttl']) return null; // expired
    $raw = @file_get_contents($CFG['schema_cache_file']);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
function saveSchemaCache(array $schema): void {
    global $CFG;
    ensureSchemaCacheReady();
    @file_put_contents($CFG['schema_cache_file'], json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    @chmod($CFG['schema_cache_file'], 0664);
}
function getSchemaSummaryWithCache(PDO $pdo, string $dbName, bool $forceRefresh = false): array {
    if (!$forceRefresh) {
        $cached = loadSchemaCache();
        if ($cached) return ['schema' => $cached, 'cache' => 'hit'];
    }
    global $CFG;
    $schema = summarizeSchema($pdo, $dbName, $CFG['max_schema_tables'], $CFG['sample_rows_per_tbl']);
    saveSchemaCache($schema);
    return ['schema' => $schema, 'cache' => $forceRefresh ? 'refresh' : 'miss'];
}

/* ---------- 스키마 요약 ---------- */
function summarizeSchema(PDO $pdo, string $dbName, int $maxTables, int $sampleRows): array {
    $tables = $pdo->prepare("
        SELECT TABLE_NAME, TABLE_COMMENT, TABLE_ROWS
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = :db AND TABLE_TYPE='BASE TABLE'
        ORDER BY COALESCE(TABLE_ROWS,0) DESC, TABLE_NAME
        LIMIT :lim
    ");
    $tables->bindValue(':db', $dbName);
    $tables->bindValue(':lim', $maxTables, PDO::PARAM_INT);
    $tables->execute();
    $tblList = $tables->fetchAll();
    if (!$tblList) return ['tables' => [], 'columns' => [], 'fks' => [], 'samples' => []];

    $in = implode(',', array_fill(0, count($tblList), '?'));
    $tblNames = array_column($tblList, 'TABLE_NAME');

    $cols = pdo()->prepare("
        SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, ORDINAL_POSITION
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($in)
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");
    $params = array_merge([ $dbName ], $tblNames);
    $cols->execute($params);
    $colList = $cols->fetchAll();

    $fks = pdo()->prepare("
        SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($in) AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME, COLUMN_NAME
    ");
    $fks->execute($params);
    $fkList = $fks->fetchAll();

    $samples = [];
    if ($sampleRows > 0) {
        foreach ($tblNames as $t) {
            try {
                $stmt = $pdo->query("SELECT * FROM `".$t."` LIMIT ".intval($sampleRows));
                $samples[$t] = $stmt->fetchAll();
            } catch (Throwable $e) {
                $samples[$t] = [];
            }
        }
    }

    return [
        'tables'  => $tblList,
        'columns' => $colList,
        'fks'     => $fkList,
        'samples' => $samples,
    ];
}
function makeSchemaPrompt(array $schema): string {
    global $CFG;
    $lines = [];
    $lines[] = "SCHEMA SUMMARY (MariaDB, database=".$CFG['db_name'].")";
    $byTableCols = [];
    foreach ($schema['columns'] as $c) {
        $byTableCols[$c['TABLE_NAME']][] =
            "{$c['COLUMN_NAME']} {$c['COLUMN_TYPE']}"
            .(strtoupper($c['IS_NULLABLE'])==='NO' ? " NOT NULL":"")
            .($c['COLUMN_KEY']==='PRI' ? " [PK]":"")
            .($c['COLUMN_KEY']==='MUL' ? " [IDX]":"")
            .($c['EXTRA'] ? " [".$c['EXTRA']."]":"")
            .(isset($c['COLUMN_DEFAULT']) && $c['COLUMN_DEFAULT']!==null ? " DEFAULT=".json_encode($c['COLUMN_DEFAULT']):"");
    }
    $byTableFK = [];
    foreach ($schema['fks'] as $f) {
        $byTableFK[$f['TABLE_NAME']][] = "{$f['COLUMN_NAME']} -> {$f['REFERENCED_TABLE_NAME']}.{$f['REFERENCED_COLUMN_NAME']}";
    }

    foreach ($schema['tables'] as $t) {
        $name = $t['TABLE_NAME'];
        $comment = $t['TABLE_COMMENT'] ?? '';
        $rows = $t['TABLE_ROWS'] ?? 0;
        $lines[] = "\nTABLE {$name}  // rows~{$rows}".($comment ? " // {$comment}":"");
        if (!empty($byTableCols[$name])) {
            foreach ($byTableCols[$name] as $colLine) $lines[] = "  - ".$colLine;
        }
        if (!empty($byTableFK[$name])) {
            $lines[] = "  FK:";
            foreach ($byTableFK[$name] as $fkLine) $lines[] = "    * ".$fkLine;
        }
        if (!empty($schema['samples'][$name])) {
            $lines[] = "  SAMPLE_ROWS_
