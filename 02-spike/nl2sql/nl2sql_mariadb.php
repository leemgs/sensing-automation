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
            $lines[] = "  SAMPLE_ROWS: ".json_encode($schema['samples'][$name], JSON_UNESCAPED_UNICODE);
        }
    }
    return implode("\n", $lines);
}

/* ---------- OpenAI 호출 ---------- */
function openaiGenerateSql(string $naturalQuestion, string $schemaText): string {
    global $CFG;

    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if (!$apiKey) throw new RuntimeException("OPENAI_API_KEY 환경변수가 설정되어 있지 않습니다.");

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
        "model"       => $CFG['openai_model'],
        "input"       => [
            [ "role" => "system", "content" => $system ],
            [ "role" => "user",   "content" => $user   ],
        ],
        "temperature" => $CFG['openai_temperature'],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $CFG['openai_endpoint'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer ".$apiKey,
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    if ($err) throw new RuntimeException("OpenAI API 요청 실패: ".$err);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("OpenAI API 에러 (HTTP {$code}): ".$resp);
    }

    $json = json_decode($resp, true);
    $text = $json['output_text'] ?? null;
    if (!$text && isset($json['output']) && is_array($json['output'])) {
        $chunks = [];
        foreach ($json['output'] as $item) {
            if (isset($item['content'][0]['text'])) $chunks[] = $item['content'][0]['text'];
        }
        $text = implode("\n", $chunks);
    }
    if (!$text) $text = (string)$resp;

    $sql = extractSqlFromText($text);
    return trim($sql);
}
function extractSqlFromText(string $text): string {
    if (preg_match('/```sql\\s*(.*?)```/is', $text, $m)) return $m[1];
    if (preg_match('/```\\s*(.*?)```/is', $text, $m))     return $m[1];
    return $text;
}

/* ---------- SQL 유효성 & 실행 ---------- */
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

/* ---------- 요청/응답 ---------- */
$question = $_POST['question'] ?? '';
$action   = $_POST['action']   ?? 'generate';
$force    = isset($_POST['schema_refresh']) && $_POST['schema_refresh'] === '1';

$generatedSql = '';
$resultRows   = [];
$errorMsg     = '';
$cacheState   = '';

try {
    if ($question) {
        $schemaPack = getSchemaSummaryWithCache(pdo(), $CFG['db_name'], $force);
        $schema     = $schemaPack['schema'];
        $cacheState = $schemaPack['cache'];
        $schemaT    = makeSchemaPrompt($schema);

        if ($action === 'generate' || $action === 'execute') {
            $generatedSql = openaiGenerateSql($question, $schemaT);

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
    DB: <b><?=html($CFG['db_name'])?></b> @ <?=html($CFG['db_host'])?>:<?=html($CFG['db_port'])?>
    · 모델: <b><?=html($CFG['openai_model'])?></b>
    · 실행 허용: <?= $CFG['execution_enabled'] ? 'ON' : 'OFF' ?>
    <?php if (!empty($cacheState)): ?><span class="badge">캐시: <?=html($cacheState)?></span><?php endif; ?>
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
      <li><b>환경변수</b>: <kbd>OPENAI_API_KEY</kbd>를 서버에 설정하세요.</li>
      <li><b>.env</b>에 DB 및 앱 설정을 저장하고, 파일 권한은 <code>0640</code> 또는 그 이하로 제한하세요.</li>
      <li><b>읽기 전용 계정</b> 사용을 권장합니다. (권한: <code>SELECT</code> on <code><?=html($CFG['db_name'])?></code>).</li>
      <li>실행을 허용하려면 .env의 <code>EXECUTION_ENABLED=true</code>로 설정하세요.</li>
      <li>생성된 SQL은 내부 검증(SELECT/WITH만 허용·위험 토큰 차단·LIMIT 강제)을 통과해야 실행됩니다.</li>
      <li>스키마 캐시는 TTL <?=$CFG['schema_cache_ttl']?>초 동안 유지됩니다. 체크박스로 강제 새로고침 가능.</li>
      <li>로그: <code><?=html($CFG['log_file'])?></code> · 캐시: <code><?=html($CFG['schema_cache_file'])?></code></li>
    </ul>
  </details>

  <p class="meta">OpenAI API: Responses API 사용 · PHP cURL 필요</p>
</body>
</html>
