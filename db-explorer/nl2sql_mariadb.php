<?php
/**
 * nl2sql.php — Natural Language to SQL (MariaDB) with OpenAI Responses API
 * PHP 8.1+ / MariaDB 10.5+ / Apache or PHP-FPM
 *
 * Security defaults:
 * - Only SELECT/WITH queries are allowed.
 * - Auto-append LIMIT 200 if missing (configurable).
 * - Block dangerous tokens (DROP/DELETE/UPDATE/INSERT/ALTER/TRUNCATE/REPLACE/LOAD/OUTFILE/etc).
 *
 * Setup:
 *   1) putenv('OPENAI_API_KEY=sk-...');  // or export in shell / service
 *   2) set DB_* constants below
 *   3) open http://yourhost/nl2sql.php
 */

// ==== CONFIG ====
const DB_HOST   = '127.0.0.1';
const DB_PORT   = 3306;
const DB_NAME   = 'regulation';         // <- 사용할 데이터베이스명
const DB_USER   = 'readonly_user';      // <- 읽기 전용 계정 권장
const DB_PASS   = 'readonly_password';  // <- 비밀번호
const CHARSET   = 'utf8mb4';

const OPENAI_MODEL        = 'gpt-4o-mini'; // 가볍고 합리적. 필요시 gpt-4o / o4-mini 등 변경
const OPENAI_ENDPOINT     = 'https://api.openai.com/v1/responses'; // Responses API
const OPENAI_TEMPERATURE  = 0.1;

const MAX_SCHEMA_TABLES   = 30;   // 너무 큰 스키마 방지: 상위 N개 테이블만 요약
const SAMPLE_ROWS_PER_TBL = 0;    // 0 = 샘플 데이터 미포함 (토큰 절감)
const AUTO_LIMIT_DEFAULT  = 200;  // 실행 시 LIMIT 강제 상한
const EXECUTION_ENABLED   = false; // 초기값: 생성만. 실행은 사용자가 버튼으로 수행.

// ==== BOOT ====
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');

function pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=".CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    // 안전을 위해(실수 방지); SELECT만 허용할 것이므로 영향은 없음
    $pdo->exec("SET SESSION SQL_SAFE_UPDATES=1");
    return $pdo;
}

function html($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---- 1) 스키마 요약 ----
function summarizeSchema(PDO $pdo, string $dbName, int $maxTables = MAX_SCHEMA_TABLES, int $sampleRows = SAMPLE_ROWS_PER_TBL): array {
    // 테이블 메타 + 대략적 행수
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

    if (!$tblList) return ['tables' => [], 'columns' => [], 'fks' => []];

    $in = implode(',', array_fill(0, count($tblList), '?'));
    $tblNames = array_column($tblList, 'TABLE_NAME');

    // 컬럼
    $cols = pdo()->prepare("
        SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($in)
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");
    $params = array_merge([ $dbName ], $tblNames);
    $cols->execute($params);
    $colList = $cols->fetchAll();

    // 외래키
    $fks = pdo()->prepare("
        SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($in) AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME, COLUMN_NAME
    ");
    $fks->execute($params);
    $fkList = $fks->fetchAll();

    // (선택) 각 테이블 샘플 n행
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
    // compact JSON-ish text (LLM 친화적, 토큰 절약)
    $lines = [];
    $lines[] = "SCHEMA SUMMARY (MariaDB, database=".DB_NAME.")";
    $byTableCols = [];
    foreach ($schema['columns'] as $c) {
        $byTableCols[$c['TABLE_NAME']][] =
            "{$c['COLUMN_NAME']} {$c['COLUMN_TYPE']}".
            (strtoupper($c['IS_NULLABLE'])==='NO' ? " NOT NULL":"").
            ($c['COLUMN_KEY']==='PRI' ? " [PK]":"").
            ($c['COLUMN_KEY']==='MUL' ? " [IDX]":"").
            ($c['EXTRA'] ? " [".$c['EXTRA']."]":"").
            (isset($c['COLUMN_DEFAULT']) && $c['COLUMN_DEFAULT']!==null ? " DEFAULT=".json_encode($c['COLUMN_DEFAULT']):"");
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

// ---- 2) OpenAI 호출 ----
function openaiGenerateSql(string $naturalQuestion, string $schemaText): string {
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

    $system = str_replace('{AUTO_LIMIT}', (string)AUTO_LIMIT_DEFAULT, $system);

    $user = <<<USER
[USER QUESTION]
{$naturalQuestion}

[AVAILABLE DATABASE SCHEMA]
{$schemaText}
USER;

    $payload = [
        "model"       => OPENAI_MODEL,
        "input"       => [
            [
                "role"    => "system",
                "content" => $system
            ],
            [
                "role"    => "user",
                "content" => $user
            ]
        ],
        "temperature" => OPENAI_TEMPERATURE,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => OPENAI_ENDPOINT,
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
    // Responses API: output_text 또는 structured content 확인
    $text = $json['output_text'] ?? null;
    if (!$text && isset($json['output']) && is_array($json['output'])) {
        // 일부 응답 포맷 대비
        $chunks = [];
        foreach ($json['output'] as $item) {
            if (isset($item['content'][0]['text'])) $chunks[] = $item['content'][0]['text'];
        }
        $text = implode("\n", $chunks);
    }
    if (!$text) $text = (string)$resp; // 마지막 방어

    // 코드블럭에서 SQL만 추출
    $sql = extractSqlFromText($text);
    return trim($sql);
}

function extractSqlFromText(string $text): string {
    // ```sql ... ``` or ``` ... ``` 우선 추출
    if (preg_match('/```sql\\s*(.*?)```/is', $text, $m)) return $m[1];
    if (preg_match('/```\\s*(.*?)```/is', $text, $m))     return $m[1];
    // 그 외에는 본문 자체를 SQL로 간주
    return $text;
}

// ---- 3) SQL 안전성 검증 & 실행 ----
function isSelectOnly(string $sql): bool {
    // 여러 문장 분리
    $stmts = array_filter(array_map('trim', preg_split('/;\\s*/', $sql)));
    if (empty($stmts)) return false;

    $blocked = '/\\b(INSERT|UPDATE|DELETE|MERGE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|GRANT|REVOKE|LOCK|UNLOCK|CALL|LOAD\\s+DATA|OUTFILE|INTO\\s+DUMPFILE|HANDLER|SHUTDOWN|SET\\s+PASSWORD)\\b/i';
    $sqliBad = '/\\b(SLEEP\\s*\\(|BENCHMARK\\s*\\(|INFORMATION_SCHEMA\\s*\\.\\s*PROCESSLIST)\\b/i';

    foreach ($stmts as $s) {
        // 허용 시작: SELECT / WITH / EXPLAIN SELECT
        if (!preg_match('/^(SELECT|WITH|EXPLAIN\\s+SELECT)\\b/i', $s)) return false;
        if (preg_match($blocked, $s)) return false;
        if (preg_match($sqliBad, $s)) return false;
    }
    return true;
}

function ensureLimit(string $sql, int $defaultLimit = AUTO_LIMIT_DEFAULT): string {
    // SELECT ... LIMIT 존재 여부 간단 체크 (CTE 포함도 고려하여 마지막 SELECT 절 기준)
    $norm = preg_replace('/\\s+/', ' ', strtoupper($sql));
    if (str_contains($norm, 'LIMIT ')) return $sql;
    // 집계만 있는 경우 LIMIT 강제하지 않을 수도 있지만, 기본적으로 붙임
    return rtrim($sql, " \t\n\r;")." LIMIT ".intval($defaultLimit).";";
}

function runSelect(PDO $pdo, string $sql): array {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    return $rows;
}

// ==== UI 처리 ====
$question = $_POST['question'] ?? '';
$action   = $_POST['action']   ?? 'generate';

$generatedSql = '';
$resultRows   = [];
$errorMsg     = '';

try {
    if ($question) {
        $schema  = summarizeSchema(pdo(), DB_NAME, MAX_SCHEMA_TABLES, SAMPLE_ROWS_PER_TBL);
        $schemaT = makeSchemaPrompt($schema);

        if ($action === 'generate' || $action === 'execute') {
            $generatedSql = openaiGenerateSql($question, $schemaT);
            if (!isSelectOnly($generatedSql)) {
                throw new RuntimeException("안전성 검증 실패: 생성된 SQL이 SELECT/WITH 전용 제약을 위반했습니다.\n\n".$generatedSql);
            }
            if ($action === 'execute') {
                if (!EXECUTION_ENABLED) {
                    throw new RuntimeException("현재 서버 설정상 SQL 실행이 비활성화(EXECUTION_ENABLED=false) 되어 있습니다. 생성만 가능합니다.");
                }
                $runSql = ensureLimit($generatedSql, AUTO_LIMIT_DEFAULT);
                $resultRows = runSelect(pdo(), $runSql);
            }
        }
    }
} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
}

?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>NL → SQL (MariaDB + OpenAI)</title>
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
</style>
</head>
<body>
  <h1>자연어 → SQL 생성기 (MariaDB + OpenAI)</h1>
  <p class="meta">DB: <b><?=html(DB_NAME)?></b> @ <?=html(DB_HOST)?>:<?=html(DB_PORT)?> · 모델: <b><?=html(OPENAI_MODEL)?></b> · 실행 허용: <?= EXECUTION_ENABLED ? 'ON' : 'OFF' ?></p>

  <form method="post">
    <label for="question">질문 (자연어)</label><br>
    <textarea id="question" name="question" placeholder="예) 지난 30일 동안 AI기본법(본문)에서 '위험' 키워드가 포함된 조항 번호와 제목을 최신순으로 30건 보여줘."><?=html($question)?></textarea>
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
    <p class="meta">주의: 서버 설정상 LIMIT <?=AUTO_LIMIT_DEFAULT?> 가 자동 부여될 수 있습니다.</p>
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
      <li><b>환경변수</b>: <kbd>OPENAI_API_KEY</kbd>를 서버에 설정하세요 (예: <code>sudo -u www-data sh -c 'echo "export OPENAI_API_KEY=sk-..." &gt;&gt; ~/.profile'</code>).</li>
      <li><b>읽기 전용 계정</b> 사용을 권장합니다. (권한: <code>SELECT</code> on <code><?=html(DB_NAME)?></code>).</li>
      <li>실행을 허용하려면 파일 상단의 <code>EXECUTION_ENABLED</code>를 <code>true</code>로 변경하세요.</li>
      <li>생성된 SQL은 내부 검증을 통과해야 실행됩니다(SELECT/WITH만 허용·위험 토큰 차단·LIMIT 강제).</li>
    </ul>
  </details>

  <p class="meta">OpenAI API: Responses API (권장) 사용. 문서: 플랫폼 공식 레퍼런스 참조.</p>
</body>
</html>
