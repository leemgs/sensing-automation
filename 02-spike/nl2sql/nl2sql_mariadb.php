<?php
/**
 * nl2sql_mariadb.php — Natural Language to SQL (MariaDB) with OpenRouter Chat Completions
 * PHP 8.1+ / MariaDB 10.5+ / Apache or PHP-FPM
 *
 * 🔧 What’s fixed/hardened
 *  - .env loader: trims blanks/quotes; empty strings now fall back to sane defaults
 *  - Paths: /var/log or /var/cache not writable -> automatically falls back to local ./logs, ./schema_cache
 *  - DB charset fallback: utf8mb4 -> utf8 (prevents "Unknown character set" 2019)
 *  - OpenRouter call: robust headers (Authorization, Referer, X-Title), clear error messages
 *  - UI: shows effective paths and cache state; safer HTML escaping
 */

header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

/* ---------- .env loader & helpers ---------- */
function loadEnvArray(string $path): array {
    if (!is_file($path)) return [];
    $arr = @parse_ini_file($path, false, INI_SCANNER_TYPED);
    return is_array($arr) ? $arr : [];
}
function env_val(array $env, string $key, mixed $default = null): mixed {
    // .env → getenv → default ; treat empty/blank as unset
    $v = $env[$key] ?? getenv($key) ?? null;
    if (is_string($v)) {
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if ($v === '') $v = null;
    }
    return $v ?? $default;
}
function env_int(array $env, string $key, int $default): int {
    $v = env_val($env, $key, null);
    return is_numeric($v) ? (int)$v : $default;
}
function env_bool(array $env, string $key, bool $default = false): bool {
    $v = env_val($env, $key, null);
    if ($v === null) return $default;
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    if ($s === '') return $default;
    return in_array($s, ['1','true','on','yes'], true) ? true
         : (in_array($s, ['0','false','off','no'], true) ? false : $default);
}
function html($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- load config ---------- */
$ENV = loadEnvArray(__DIR__.'/.env');

$CFG = [
    // DB
    'db_host'   => env_val($ENV, 'DB_HOST', 'leemgs.mooo.com'),
    'db_port'   => env_int($ENV, 'DB_PORT', 3306),
    'db_name'   => env_val($ENV, 'DB_NAME', 'regulation'),
    'db_user'   => env_val($ENV, 'DB_USER', 'root'),
    'db_pass'   => env_val($ENV, 'DB_PASS', 'ekqlscl98'),
    'charset'   => env_val($ENV, 'DB_CHARSET', 'utf8mb4'),

    // OpenRouter (Chat Completions)
    'or_api_key'      => env_val($ENV, 'OPENROUTER_API_KEY', ''),
    'or_model'        => env_val($ENV, 'OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
    'or_endpoint'     => env_val($ENV, 'OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
    'or_temperature'  => (float)env_val($ENV, 'OPENROUTER_TEMPERATURE', 0.1),
    'or_max_tokens'   => env_int($ENV, 'OPENROUTER_MAX_TOKENS', 800),
    // optional meta headers
    'or_referer'      => env_val($ENV, 'OPENROUTER_HTTP_REFERER', ''),
    'or_title'        => env_val($ENV, 'OPENROUTER_X_TITLE', 'NL→SQL App'),

    // Safety/limits
    'max_schema_tables'   => env_int($ENV, 'MAX_SCHEMA_TABLES', 30),
    'sample_rows_per_tbl' => env_int($ENV, 'SAMPLE_ROWS_PER_TBL', 0),
    'auto_limit_default'  => env_int($ENV, 'AUTO_LIMIT_DEFAULT', 200),
    'execution_enabled'   => env_bool($ENV, 'EXECUTION_ENABLED', false),

    // Paths (will be normalized to effective paths)
    'log_dir'           => env_val($ENV, 'LOG_DIR', __DIR__ . '/logs'),
    'schema_cache_dir'  => env_val($ENV, 'SCHEMA_CACHE_DIR', __DIR__ . '/schema_cache'),
    'schema_cache_ttl'  => env_int($ENV, 'SCHEMA_CACHE_TTL', 3600),

    // UI
    'app_title' => env_val($ENV, 'APP_TITLE', '자연어 → SQL 생성기 (MariaDB + OpenRouter)'),
];

/* ---------- ensure writable dirs with fallbacks ---------- */
function ensureWritableDir(string $preferred, string $fallback): array {
    // returns [effective_path, fell_back(bool)]
    $p = rtrim($preferred, '/');
    if (@is_dir($p) || @mkdir($p, 0775, true)) {
        if (is_writable($p)) return [$p, false];
    }
    $f = rtrim($fallback, '/');
    if (!@is_dir($f)) @mkdir($f, 0775, true);
    return [$f, true];
}
[$logDirEff, $logFallback]   = ensureWritableDir($CFG['log_dir'], __DIR__.'/logs');
[$cacheDirEff, $cacheFallback] = ensureWritableDir($CFG['schema_cache_dir'], __DIR__.'/schema_cache');
$CFG['log_dir_effective']   = $logDirEff;
$CFG['schema_cache_dir_effective'] = $cacheDirEff;
$CFG['log_file']            = $CFG['log_dir_effective'] . '/queries.log';
$CFG['schema_cache_file']   = $CFG['schema_cache_dir_effective'] . '/schema.json';

/* ---------- last-resort defaults (belt & suspenders) ---------- */
$CFG['db_host']  = $CFG['db_host']  ?: 'leemgs.mooo.com';
$CFG['db_name']  = $CFG['db_name']  ?: 'regulation';
$CFG['or_model'] = $CFG['or_model'] ?: 'openai/gpt-4o-mini';

/* ---------- DB connection with charset fallback ---------- */
function pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    global $CFG;

    $candidates = array_values(array_unique([
        trim((string)$CFG['charset'] ?: ''),
        'utf8mb4',
        'utf8',
    ]));

    $lastErr = null;
    foreach ($candidates as $cs) {
        if ($cs === '') continue;
        $dsn = "mysql:host={$CFG['db_host']};port={$CFG['db_port']};dbname={$CFG['db_name']};charset={$cs}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        try {
            $pdo = new PDO($dsn, $CFG['db_user'], $CFG['db_pass'], $options);
            $pdo->exec("SET SESSION SQL_SAFE_UPDATES=1");
            // try explicit NAMES (no-op if unsupported)
            try { $pdo->exec("SET NAMES {$cs}"); } catch (Throwable $ignore) {}
            return $pdo;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'unknown character set') !== false || stripos($msg, 'charset') !== false) {
                $lastErr = $e; // try next candidate
                continue;
            }
            throw $e; // other errors -> bubble up
        }
    }
    throw ($lastErr ?: new RuntimeException('DB connection failed (charset fallback exhausted)'));
}

/* ---------- logging ---------- */
function ensureLogReady(): void {
    global $CFG;
    if (!is_dir($CFG['log_dir_effective'])) @mkdir($CFG['log_dir_effective'], 0775, true);
    if (!file_exists($CFG['log_file'])) { @touch($CFG['log_file']); @chmod($CFG['log_file'], 0664); }
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
        'sql'       => $entry['sql']      ?? '',
        'executed'  => (bool)($entry['executed'] ?? false),
        'row_count' => (int)($entry['row_count'] ?? 0),
        'error'     => $entry['error']    ?? '',
    ];
    $msg = implode("\t", [
        $line['ts'], $line['ip'], $line['action'],
        'executed=' . ($line['executed'] ? '1':'0'),
        'rows=' . $line['row_count'],
        'err=' . str_replace(["\n","\r","\t"], ' ', (string)$line['error']),
        'ua=' . str_replace(["\n","\r","\t"], ' ', $line['ua']),
        'Q=' . str_replace(["\n","\r","\t"], ' ', $line['question']),
        'SQL=' . str_replace(["\n","\r"], ' ', $line['sql']),
    ]) . PHP_EOL;
    $fh = @fopen($CFG['log_file'], 'ab');
    if ($fh) { @flock($fh, LOCK_EX); @fwrite($fh, $msg); @flock($fh, LOCK_UN); @fclose($fh); }
}

/* ---------- schema cache ---------- */
function ensureSchemaCacheReady(): void {
    global $CFG;
    if (!is_dir($CFG['schema_cache_dir_effective'])) @mkdir($CFG['schema_cache_dir_effective'], 0775, true);
}
function loadSchemaCache(): ?array {
    global $CFG;
    if (!file_exists($CFG['schema_cache_file'])) return null;
    $mtime = @filemtime($CFG['schema_cache_file']); if (!$mtime) return null;
    if ((time() - $mtime) > $CFG['schema_cache_ttl']) return null;
    $raw = @file_get_contents($CFG['schema_cache_file']); if ($raw === false || $raw === '') return null;
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

/* ---------- schema summarization ---------- */
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
            $lines[] = "  SAMPLE_ROWS: ".json_encode($schema['samples'][$name], JSON_UNESCAPED_UNICODE);
        }
    }
    return implode("\n", $lines);
}

/* ---------- OpenRouter (Chat Completions) ---------- */
function llmGenerateSql_viaOpenRouter(string $naturalQuestion, string $schemaText): string {
    global $CFG;

    if (!$CFG['or_api_key']) {
        throw new RuntimeException("OPENROUTER_API_KEY 가 설정되어 있지 않습니다 (.env).");
    }

    $system = <<<SYS
You are a senior data analyst expert in MariaDB SQL.
Task: Convert user questions into the most appropriate, efficient, and safe SQL query for MariaDB.
Constraints:
- STRICTLY RETURN A SINGLE SQL CODE BLOCK ONLY. No explanation.
- Use only SELECT or WITH CTE. NEVER write INSERT/UPDATE/DELETE/DDL.
- Prefer explicit column lists over SELECT *.
- Add reasonable filters/joins based on foreign keys and column semantics.
- If the user's ask implies multiple steps, produce a single query when possible (WITH CTEs allowed).
- If the schema doesn't contain enough info, infer best-effort joins but stay plausible.
- Always include LIMIT {AUTO_LIMIT} unless the user requests full aggregation results.
- Use backticks for identifiers with non-ASCII or spaces.
- SQL dialect: MariaDB 10.5+ compatible.
SYS;
    $system = str_replace('{AUTO_LIMIT}', (string)$CFG['auto_limit_default'], $system);

    $user = <<<USER
[USER QUESTION]
{$naturalQuestion}

[AVAILABLE DATABASE SCHEMA]
{$schemaText}
USER;

    $payload = [
        "model"       => $CFG['or_model'],
        "messages"    => [
            ["role" => "system", "content" => $system],
            ["role" => "user",   "content" => $user  ],
        ],
        "temperature" => $CFG['or_temperature'],
        "max_tokens"  => $CFG['or_max_tokens'],
    ];

    $headers = [
        "Authorization: Bearer ".$CFG['or_api_key'],
        "Content-Type: application/json",
    ];
    if (!empty($CFG['or_referer'])) $headers[] = "Referer: ".$CFG['or_referer'];         // standard
    if (!empty($CFG['or_referer'])) $headers[] = "HTTP-Referer: ".$CFG['or_referer'];    // OpenRouter doc variant
    if (!empty($CFG['or_title']))   $headers[] = "X-Title: ".$CFG['or_title'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $CFG['or_endpoint'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) throw new RuntimeException("OpenRouter API 요청 실패(cURL): ".$err);
    if ($http < 200 || $http >= 300) {
        throw new RuntimeException("OpenRouter API 에러 (HTTP {$http}): ".substr((string)$resp, 0, 500));
    }

    $json = json_decode($resp, true);
    $text = $json['choices'][0]['message']['content'] ?? null;
    if (!$text) {
        // 마지막 안전망
        $text = (string)$resp;
    }

    $sql = extractSqlFromText($text);
    if (!trim($sql)) {
        throw new RuntimeException("LLM 응답에서 SQL을 추출하지 못했습니다.");
    }
    return trim($sql);
}

/* ---------- extract SQL from LLM text ---------- */
function extractSqlFromText(string $text): string {
    if (preg_match('/```sql\\s*(.*?)```/is', $text, $m)) return $m[1];
    if (preg_match('/```\\s*(.*?)```/is', $text, $m))     return $m[1];
    return $text;
}

/* ---------- SQL validation & execution ---------- */
function isSelectOnly(string $sql): bool {
    $stmts = array_filter(array_map('trim', preg_split('/;\\s*/', $sql)));
    if (empty($stmts)) return false;
    $blocked = '/\\b(INSERT|UPDATE|DELETE|MERGE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|GRANT|REVOKE|LOCK|UNLOCK|CALL|LOAD\\s+DATA|OUTFILE|INTO\\s+DUMPFILE|HANDLER|SHUTDOWN|SET\\s+PASSWORD)\\b/i';
    $sqliBad = '/\\b(SLEEP\\s*\\(|BENCHMARK\\s*\\(|INFORMATION_SCHEMA\\s*\\.\\s*PROCESSLIST)\\b/i';
    foreach ($stmts as $s) {
        if (!preg_match('/^(SELECT|WITH|EXPLAIN\\s+SELECT)\\b/i', $s)) return false;
        if (preg_match($blocked, $s)) return false;
        if (preg_match($sqliBad, $s)) return false;
    }
    return true;
}
function ensureLimit(string $sql, int $defaultLimit): string {
    $norm = preg_replace('/\\s+/', ' ', strtoupper($sql));
    if (str_contains($norm, 'LIMIT ')) return $sql;
    return rtrim($sql, " \t\n\r;")." LIMIT ".intval($defaultLimit).";";
}
function runSelect(PDO $pdo, string $sql): array {
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/* ---------- request/response ---------- */
$question = $_POST['question'] ?? '';
$action   = $_POST['action']   ?? 'generate';
$force    = isset($_POST['schema_refresh']) && $_POST['schema_refresh'] === '1';

$generatedSql = '';
$resultRows   = [];
$errorMsg     = '';
$cacheState   = '';
$fallbackNotes = [];
if ($logFallback)   $fallbackNotes[] = 'log->local';
if ($cacheFallback) $fallbackNotes[] = 'cache->local';

try {
    if ($question) {
        $schemaPack = getSchemaSummaryWithCache(pdo(), $CFG['db_name'], $force);
        $schema     = $schemaPack['schema'];
        $cacheState = $schemaPack['cache'];
        $schemaT    = makeSchemaPrompt($schema);

        if ($action === 'generate' || $action === 'execute') {
            $generatedSql = llmGenerateSql_viaOpenRouter($question, $schemaT);

            if (!isSelectOnly($generatedSql)) {
                logQuery([
                    'action'   => $action . " (cache:$cacheState)",
                    'question' => $question,
                    'sql'      => $generatedSql,
                    'executed' => false,
                    'row_count'=> 0,
                    'error'    => 'validation_failed: not select-only',
                ]);
                throw new RuntimeException("안전성 검증 실패: 생성된 SQL이 SELECT/WITH 전용 제약을 위반했습니다.\n\n".$generatedSql);
            }

            if ($action === 'generate') {
                logQuery([
                    'action'   => 'generate' . " (cache:$cacheState)",
                    'question' => $question,
                    'sql'      => $generatedSql,
                    'executed' => false,
                    'row_count'=> 0,
                    'error'    => '',
                ]);
            }

            if ($action === 'execute') {
                if (!$CFG['execution_enabled']) {
                    logQuery([
                        'action'   => 'execute' . " (cache:$cacheState)",
                        'question' => $question,
                        'sql'      => $generatedSql,
                        'executed' => false,
                        'row_count'=> 0,
                        'error'    => 'execution_disabled',
                    ]);
                    throw new RuntimeException("현재 서버 설정상 SQL 실행이 비활성화(EXECUTION_ENABLED=false) 되어 있습니다. 생성만 가능합니다.");
                }
                $runSql = ensureLimit($generatedSql, $CFG['auto_limit_default']);
                $resultRows = runSelect(pdo(), $runSql);
                logQuery([
                    'action'   => 'execute' . " (cache:$cacheState)",
                    'question' => $question,
                    'sql'      => $runSql,
                    'executed' => true,
                    'row_count'=> is_array($resultRows) ? count($resultRows) : 0,
                    'error'    => '',
                ]);
            }
        }
    }
} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
    logQuery([
        'action'   => $action ?? 'unknown',
        'question' => $question ?? '',
        'sql'      => $generatedSql ?? '',
        'executed' => ($action ?? '') === 'execute',
        'row_count'=> 0,
        'error'    => $errorMsg,
    ]);
}

/* ---------- UI ---------- */
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title><?=html($CFG['app_title'])?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Noto Sans KR',sans-serif;max-width:960px;margin:24px auto;padding:0 16px;line-height:1.5}
h1{font-size:22px;margin:0 0 12px}
label{font-weight:600}
textarea{width:100%;min-height:120px;padding:10px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
pre{background:#0b1020;color:#e8ecff;padding:12px;border-radius:8px;overflow:auto}
code{white-space:pre}
.btns{display:flex;gap:8px;flex-wrap:wrap}
button{padding:10px 14px;border:0;border-radius:8px;background:#3b82f6;color:white;cursor:pointer}
button.secondary{background:#475569}
table{border-collapse:collapse;width:100%;margin-top:8px}
th,td{border:1px solid #e2e8f0;padding:6px 8px;font-size:13px}
th{background:#f1f5f9;text-align:left}
.error{background:#fff1f2;color:#b91c1c;border:1px solid #fecaca;padding:12px;border-radius:8px;white-space:pre-wrap}
.meta{color:#64748b;font-size:12px}
kbd{background:#e2e8f0;border-radius:4px;padding:0 6px}
small.badge{display:inline-block;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;border-radius:6px;padding:2px 6px;margin-left:6px}
</style>
</head>
<body>
  <h1><?=html($CFG['app_title'])?></h1>
  <p class="meta">
    DB: <b><?=html($CFG['db_name'] ?: '(unset)')?></b>
    @ <?=html($CFG['db_host'] ?: '(unset)')?>:<?=html($CFG['db_port'])?>
    · 모델: <b><?=html($CFG['or_model'] ?: '(unset)')?></b>
    · 실행 허용: <?= $CFG['execution_enabled'] ? 'ON' : 'OFF' ?>
    <?php if (!empty($cacheState)): ?><span class="badge">캐시: <?=html($cacheState)?></span><?php endif; ?>
    <?php if ($fallbackNotes): ?><span class="badge">폴백: <?=html(implode(',', $fallbackNotes))?></span><?php endif; ?>
  </p>

  <form method="post">
    <label for="question">질문 (자연어)</label><br>
    <textarea id="question" name="question" placeholder="예) 지난 30일 동안 AI기본법(본문)에서 '위험' 키워드가 포함된 조항 번호와 제목을 최신순으로 30건 보여줘."><?=html($question)?></textarea>
    <div style="margin-top:8px">
      <label style="display:inline-flex;align-items:center;gap:6px">
        <input type="checkbox" name="schema_refresh" value="1">
        스키마 캐시 무시하고 새로고침
      </label>
    </div>
    <div class="btns" style="margin-top:8px">
      <button type="submit" name="action" value="generate">SQL 생성</button>
      <button type="submit" name="action" value="execute" class="secondary" title="SELECT 전용 · LIMIT 강제">생성 &amp; 실행</button>
    </div>
  </form>

  <?php if ($errorMsg): ?>
    <div class="error"><b>오류:</b> <?=nl2br(html($errorMsg))?></div>
  <?php endif; ?>

  <?php if ($generatedSql): ?>
    <h2>생성된 SQL</h2>
    <pre><code><?=html(trim($generatedSql))?></code></pre>
    <p class="meta">주의: 실행 시 LIMIT <?=$CFG['auto_limit_default']?> 가 자동 부여될 수 있습니다.</p>
  <?php endif; ?>

  <?php if ($resultRows): ?>
    <h2>실행 결과 (<?=count($resultRows)?> rows)</h2>
    <div style="overflow:auto">
      <table>
        <thead>
          <tr>
            <?php foreach(array_keys($resultRows[0]) as $h): ?>
              <th><?=html($h)?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($resultRows as $r): ?>
            <tr>
              <?php foreach($r as $v): ?>
                <td><?=html(is_scalar($v)? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE))?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php elseif ($generatedSql && $action==='execute'): ?>
    <p class="meta">행이 없습니다.</p>
  <?php endif; ?>

  <hr style="margin:24px 0">
  <details>
    <summary><b>도움말</b> (열기)</summary>
    <ul>
      <li><b>.env</b>에 <kbd>OPENROUTER_API_KEY</kbd>를 설정하세요. (현재: <?=html($CFG['or_api_key'] ? '설정됨' : '미설정')?>)</li>
      <li>모델/엔드포인트는 <code>OPENROUTER_MODEL</code>, <code>OPENROUTER_ENDPOINT</code>로 제어합니다.</li>
      <li>실행을 허용하려면 <code>EXECUTION_ENABLED=true</code>로 설정(읽기 전용 계정 권장).</li>
      <li>스키마 캐시 TTL: <?=$CFG['schema_cache_ttl']?>초 · 캐시 파일: <code><?=html($CFG['schema_cache_file'])?></code></li>
      <li>로그 파일: <code><?=html($CFG['log_file'])?></code> (경로 권한 이슈 시 자동 폴백)</li>
    </ul>
  </details>

  <p class="meta">OpenRouter API (Chat Completions) · PHP cURL 필요</p>
</body>
</html>
