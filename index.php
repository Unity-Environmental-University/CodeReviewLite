<?php
// CodeReviewLite - single-file PHP snippet review tool
// Drop this file on any PHP-capable host. Create a writable ./data directory next to it.
// Paste/upload code, get a shareable link, and collect per-line threaded comments.
// Attach the link to your Trello card.

// --- Configuration -----------------------------------------------------------
if (!defined('APP_NAME')) { define('APP_NAME', 'CodeReviewLite'); }
if (!defined('DATA_DIR')) { define('DATA_DIR', __DIR__ . '/data'); }
if (!defined('ALLOW_FILE_UPLOADS')) { define('ALLOW_FILE_UPLOADS', false); }
if (!defined('REQUIRE_CREATE_TOKEN')) { define('REQUIRE_CREATE_TOKEN', false); }
if (!defined('CREATE_TOKEN')) { define('CREATE_TOKEN', ''); }
if (!defined('ALLOW_ANON_COMMENTS')) { define('ALLOW_ANON_COMMENTS', true); }
if (!defined('ADMIN_DELETE_TOKEN')) { define('ADMIN_DELETE_TOKEN', ''); }
if (!defined('MAX_FILES')) { define('MAX_FILES', 10); }
if (!defined('MAX_SIZE')) { define('MAX_SIZE', 500 * 1024); }

// --- Bootstrap --------------------------------------------------------------
ensure_data_dir();

// Prevent intermediary caches from serving stale reviews
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

// Helpers
function ensure_data_dir(): void {
  if (is_dir(DATA_DIR) || @mkdir(DATA_DIR, 0775, true)) {
    if (is_writable(DATA_DIR)) {
      return;
    }
    fail_setup('Make the <code>data/</code> directory writable beside this file.');
  }
  fail_setup('Unable to create required <code>data/</code> directory.');
}

function fail_setup(string $message): void {
  http_response_code(500);
  echo '<h1>Setup error</h1><p>' . $message . '</p>';
  exit;
}

function uuid(): string { return bin2hex(random_bytes(8)); }
function path_for(string $id): string { return DATA_DIR . '/' . $id . '.json'; }
function comments_path_for(string $id): string { return DATA_DIR . '/' . $id . '-comments.json'; }
function load_json(string $path, $default) {
  if (!file_exists($path)) {
    return $default;
  }
  if (!is_readable($path)) {
    error_log('CodeReviewLite: unable to read "' . $path . '" — check filesystem permissions.');
    return $default;
  }
  $json = file_get_contents($path);
  if ($json === false) {
    error_log('CodeReviewLite: file_get_contents failed for "' . $path . '".');
    return $default;
  }
  $decoded = json_decode($json, true);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    error_log('CodeReviewLite: JSON decode error for "' . $path . '": ' . json_last_error_msg());
    return $default;
  }
  if (!is_array($decoded)) {
    error_log('CodeReviewLite: unexpected JSON structure in "' . $path . '".');
    return $default;
  }
  return $decoded;
}
function save_json_atomic(string $path, array $data): bool {
  $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) {
    return false;
  }
  $tmp = $path . '.tmp';
  $fp = fopen($tmp, 'wb');
  if (!$fp) {
    return false;
  }
  fwrite($fp, $encoded);
  fclose($fp);
  return @rename($tmp, $path);
}
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function is_admin(): bool {
  return ADMIN_DELETE_TOKEN && (($_GET['admin'] ?? '') === ADMIN_DELETE_TOKEN);
}
function request_method(): string { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }
function is_post(): bool { return request_method() === 'POST'; }
function get_param(string $key, $default = null) { return $_GET[$key] ?? $default; }
function post_param(string $key, $default = null) { return $_POST[$key] ?? $default; }
function request_action(): string { return get_param('action', post_param('action', '')); }
function request_id(): string { return get_param('id', post_param('id', '')); }
function current_admin_token(): string { return $_GET['admin'] ?? ''; }
function redirect_self(array $query = [], string $anchor = ''): void {
  $path = $_SERVER['PHP_SELF'] ?? '';
  if ($query) {
    $path .= '?' . http_build_query($query);
  }
  if ($anchor !== '') {
    $path .= '#' . ltrim($anchor, '#');
  }
  header('Location: ' . $path);
  exit;
}
function abort_with(int $status, string $message): void {
  http_response_code($status);
  echo $message;
  exit;
}
function enforce_create_token(): void {
  if (!REQUIRE_CREATE_TOKEN) {
    return;
  }
  if (get_param('token', '') === CREATE_TOKEN) {
    return;
  }
  abort_with(403, 'Forbidden: invalid token');
}
function clamp_text(string $text): string {
  if (strlen($text) <= MAX_SIZE) {
    return $text;
  }
  return substr($text, 0, MAX_SIZE);
}
function with_admin(array $params): array {
  $token = current_admin_token();
  if ($token !== '') {
    $params['admin'] = $token;
  }
  return $params;
}
function language_hint_for(string $filename, string $global): string {
  $map = [
    'php' => 'php',
    'js' => 'javascript',
    'ts' => 'typescript',
    'jsx' => 'jsx',
    'tsx' => 'tsx',
    'py' => 'python',
    'rb' => 'ruby',
    'java' => 'java',
    'cs' => 'csharp',
    'c' => 'c',
    'cpp' => 'cpp',
    'cc' => 'cpp',
    'cxx' => 'cpp',
    'h' => 'c',
    'hpp' => 'cpp',
    'go' => 'go',
    'rs' => 'rust',
    'swift' => 'swift',
    'kt' => 'kotlin',
    'sql' => 'sql',
    'sh' => 'bash',
    'bash' => 'bash',
    'html' => 'xml',
    'htm' => 'xml',
    'css' => 'css',
    'json' => 'json',
    'xml' => 'xml',
    'yml' => 'yaml',
    'yaml' => 'yaml',
  ];

  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  if ($ext && isset($map[$ext])) {
    return $map[$ext];
  }

  $hint = strtolower($global);
  if ($hint !== '' && $hint !== 'auto') {
    return $map[$hint] ?? $hint;
  }

  return '';
}

// Routing
$action = request_action();
switch ($action) {
  case 'create':
    if (!is_post()) { break; }
    handle_create();
    break;
  case 'comment':
    if (!is_post()) { break; }
    handle_comment();
    break;
  case 'resolve':
    handle_resolve();
    break;
  case 'delete':
    handle_delete();
    break;
}

function handle_create(): void {
  enforce_create_token();

  $title = trim((string) post_param('title', '')); // fallback for empty string is done below
  $author = trim((string) post_param('author', ''));
  $language = trim((string) post_param('language', 'auto')) ?: 'auto';

  $files = collect_pasted_files();
  if (ALLOW_FILE_UPLOADS && !empty($_FILES['files'])) {
    $files = array_merge($files, collect_uploaded_files($_FILES['files'], count($files)));
  }

  if (empty($files)) {
    redirect_self(['error' => 'No files provided']);
  }

  $documentId = uuid();
  $doc = [
    'id' => $documentId,
    'title' => $title !== '' ? $title : 'Untitled Review',
    'author' => $author,
    'language' => $language,
    'created_at' => now_iso(),
    'files' => $files,
    'resolved' => false,
  ];

  if (!save_json_atomic(path_for($documentId), $doc)) {
    abort_with(500, 'Failed to persist snippet.');
  }
  if (!save_json_atomic(comments_path_for($documentId), [])) {
    abort_with(500, 'Failed to persist comments.');
  }

  redirect_self(['id' => $documentId]);
}

function collect_pasted_files(): array {
  $names = post_param('filename', []);
  $contents = post_param('content', []);
  if (!is_array($names)) { $names = [$names]; }
  if (!is_array($contents)) { $contents = [$contents]; }

  $files = [];
  $count = max(count($names), count($contents));
  for ($i = 0; $i < $count && count($files) < MAX_FILES; $i++) {
    $content = isset($contents[$i]) ? (string) $contents[$i] : '';
    if ($content === '') {
      continue;
    }
    $name = isset($names[$i]) ? trim((string) $names[$i]) : '';
    $files[] = [
      'name' => $name !== '' ? $name : 'snippet_' . ($i + 1),
      'content' => clamp_text($content),
    ];
  }

  return $files;
}

function collect_uploaded_files(array $uploads, int $existingCount): array {
  $files = [];
  if (!isset($uploads['name'], $uploads['error'], $uploads['tmp_name'])) {
    return $files;
  }

  $names = $uploads['name'];
  $errors = $uploads['error'];
  $tmpNames = $uploads['tmp_name'];
  $total = is_array($names) ? count($names) : (isset($names) ? 1 : 0);

  for ($i = 0; $i < $total && ($existingCount + count($files)) < MAX_FILES; $i++) {
    $error = is_array($errors) ? $errors[$i] : $errors;
    $tmp = is_array($tmpNames) ? $tmpNames[$i] : $tmpNames;
    if ($error !== UPLOAD_ERR_OK || !is_readable($tmp)) {
      continue;
    }

    $data = file_get_contents($tmp);
    if ($data === false) {
      continue;
    }

    $name = is_array($names) ? $names[$i] : $names;
    $files[] = [
      'name' => (string) $name,
      'content' => clamp_text($data),
    ];
  }

  return $files;
}

function handle_comment(): void {
  $id = trim((string) post_param('id', ''));
  if ($id === '') {
    abort_with(400, 'Bad request');
  }

  $doc = load_json(path_for($id), null);
  if (!$doc) {
    abort_with(404, 'Not found');
  }

  $fileIndex = max(0, (int) post_param('file', 0));
  if (!isset($doc['files'][$fileIndex])) {
    $fileIndex = 0;
  }

  $startLine = max(1, (int) post_param('start_line', post_param('line', 1)));
  $endLine = max($startLine, (int) post_param('end_line', $startLine));
  $line = $startLine;
  $who = trim((string) post_param('who', ''));
  $body = trim((string) post_param('body', ''));
  $kind = post_param('kind', 'comment') === 'edit' ? 'edit' : 'comment';
  $suggestionRaw = (string) post_param('suggestion', '');
  $suggestion = clamp_text($suggestionRaw);
  if ($kind === 'edit') {
    if (trim($suggestionRaw) === '') {
      abort_with(400, 'Suggested edit required');
    }
  } elseif ($body === '') {
    abort_with(400, 'Comment body required');
  }
  if (!ALLOW_ANON_COMMENTS && $who === '') {
    abort_with(403, 'Name required');
  }

  $comments = load_json(comments_path_for($id), []);
  if (!is_array($comments)) {
    $comments = [];
  }

  $comments[] = [
    'id' => uuid(),
    'file' => $fileIndex,
    'line' => $line,
    'line_end' => $endLine,
    'who' => $who !== '' ? $who : 'Anonymous',
    'body' => $body,
    'created_at' => now_iso(),
    'resolved' => false,
    'kind' => $kind,
    'suggestion' => $suggestion,
  ];

  if (!save_json_atomic(comments_path_for($id), $comments)) {
    abort_with(500, 'Failed to save comment');
  }

  redirect_self(with_admin(['id' => $id]), 'f' . $fileIndex . 'l' . $line);
}

function handle_resolve(): void {
  if (!is_admin()) {
    abort_with(403, 'Forbidden');
  }

  $id = trim((string) get_param('id', ''));
  if ($id === '') {
    abort_with(400, 'Bad request');
  }

  $commentId = trim((string) get_param('cid', ''));
  if ($commentId !== '') {
    $comments = load_json(comments_path_for($id), []);
    $updated = false;
    foreach ($comments as &$comment) {
      if (($comment['id'] ?? null) === $commentId) {
        $comment['resolved'] = true;
        $updated = true;
        break;
      }
    }
    unset($comment);
    if ($updated && !save_json_atomic(comments_path_for($id), $comments)) {
      abort_with(500, 'Failed to update comment state');
    }
  } else {
    $doc = load_json(path_for($id), null);
    if ($doc) {
      $current = !empty($doc['resolved']);
      $doc['resolved'] = !$current;
      if (!save_json_atomic(path_for($id), $doc)) {
        abort_with(500, 'Failed to update snippet state');
      }
    }
  }

  redirect_self(with_admin(['id' => $id]));
}

function handle_delete(): void {
  if (!is_admin()) {
    abort_with(403, 'Forbidden');
  }

  $id = trim((string) get_param('id', ''));
  if ($id !== '') {
    @unlink(path_for($id));
    @unlink(comments_path_for($id));
  }

  redirect_self(with_admin([]));
}

function group_comments_by_file(array $comments): array {
  $grouped = [];
  foreach ($comments as $comment) {
    $index = isset($comment['file']) ? (int) $comment['file'] : 0;
    if (!isset($grouped[$index])) {
      $grouped[$index] = ['all' => [], 'open' => 0];
    }
    $grouped[$index]['all'][] = $comment;
    if (empty($comment['resolved'])) {
      $grouped[$index]['open']++;
    }
  }
  return $grouped;
}

// Render
$id = request_id();
$doc = $id !== '' ? load_json(path_for($id), null) : null;
$comments = $id !== '' ? load_json(comments_path_for($id), []) : [];
$commentsByFile = group_comments_by_file(is_array($comments) ? $comments : []);
$err = $_GET['error'] ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= h(APP_NAME) ?><?= $doc ? ' · ' . h($doc['title']) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<style>
  :root { --bg:#080b16; --card:#11182b; --muted:#8ea4c0; --text:#f1f5ff; --accent:#6f9cff; --ok:#1cd38a; --warn:#f6c85f; --danger:#ff6b6b; --border:#222f4c; --surface:#0c1324; }
  *{box-sizing:border-box} body{margin:0;background:radial-gradient(circle at top,#0c1330 0%,#070913 60%,#06070c 100%);color:var(--text);font:14px/1.5 Inter,system-ui,Segoe UI,Roboto,Arial}
  a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
  .wrap{width:min(1600px,96vw);margin:0 auto;padding:32px 36px 52px}
  h1 a{color:inherit;text-decoration:none}
  h1 a:hover{text-decoration:underline}
  .card{background:var(--card);border:1px solid var(--border);border-radius:18px;box-shadow:0 16px 40px rgba(3,8,20,.35);padding:22px;margin-bottom:22px;overflow:visible}
  h1,h2{margin:0 0 12px} h1{font-size:22px} h2{font-size:17px;color:#c3d4f5}
  .row{display:flex;gap:12px;flex-wrap:wrap}
  .grow{flex:1}
  input,select,textarea,button{background:var(--surface);color:var(--text);border:1px solid #2b3a5b;border-radius:12px;padding:10px;font:14px Inter;width:100%;transition:.2s border,.2s box-shadow}
  input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(111,156,255,.25);outline:none}
  button{cursor:pointer;background:linear-gradient(180deg,#18294d,#101b36);border:1px solid #2b3f6a}
  button.primary{background:linear-gradient(180deg,#3b66ff,#1f3fd6);border-color:#3c5ee0;box-shadow:0 8px 18px rgba(63,110,255,.28)}
  .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, "Liberation Mono", monospace; font-size:12.5px}
  .file{margin-top:6px}
  .code{position:relative;border:1px solid #26314a;border-radius:12px;overflow:auto}
  .linenos{counter-reset: line; padding-left: 0; margin:0; background:#101a32;border:1px solid #1d2c4f;border-radius:14px;overflow-y:auto;overflow-x:hidden}
  .linenos::-webkit-scrollbar{width:10px;height:10px}
  .linenos::-webkit-scrollbar-thumb{background:#1f3155;border-radius:999px}
  .linenos::-webkit-scrollbar-track{background:#0b1429}
  .linenos{scrollbar-color:#1f3155 #0b1429; scrollbar-width:thin}
  .linenos li{display:block; white-space:pre; padding:8px 16px 8px 56px; position:relative; min-height:20px; line-height:1.35; scroll-margin-top:120px}
  .linenos li::before{counter-increment: line; content: counter(line); position:absolute; left:16px; top:8px; width:30px; text-align:right; color:#5f7195; padding-right:6px}
  .code-line{cursor:pointer; transition:background .15s; overflow-x:hidden}
  .code-line.overflowing{overflow-x:auto}
  .code-line span{display:inline; white-space:pre}
  .code-line span{tab-size:2;-moz-tab-size:2}
  .hl{background: rgba(122,162,255,0.12)}
  .comment{background:#121e35;border:1px solid #23365f;border-radius:14px;padding:14px;margin:0;box-shadow:0 10px 28px rgba(0,0,0,.25);word-break:break-word;overflow-wrap:break-word}
  .comment.small{font-size:12px;color:#b9c3d3}
  .badge{display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;border:1px solid #2d3b60;background:#0f1630}
  .ok{color:#0fd58a;border-color:#0fd58a33}
  .badge.edit{color:#7aa2ff;border-color:#7aa2ff55}
  .muted{color:#9db0c6}
  .right{float:right}
  .flex{display:flex;gap:8px;align-items:center}
  .space{height:8px}
  .sticky{position:sticky; top:-22px; background:rgba(13,21,42,.92); padding:12px; z-index:3; border:1px solid rgba(111,156,255,.15); border-radius:14px; backdrop-filter:blur(12px); margin-bottom:18px}
  .pill{padding:5px 12px;border-radius:999px;background:rgba(19,31,60,.6);border:1px solid rgba(111,156,255,.25);color:#cdd9ff}
  .suggestion{margin-top:10px;padding:10px;border-radius:10px;border:1px solid #26406c;background:#0b152b}
  .suggestion pre{margin:6px 0 0;white-space:pre-wrap; word-break:break-word; overflow:auto}
  .mode-toggle{display:flex;gap:12px;flex-wrap:wrap}
  .mode-toggle label{display:inline-flex;align-items:center;gap:6px;color:#b9c3d3;font-size:13px;white-space:nowrap}
  .mode-toggle input{margin:0}
  .close-editor{cursor:pointer;position:absolute;top:4px;right:8px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:18px;line-height:1;background:transparent;border:1px solid transparent;border-radius:8px;color:#b9c3d3}
  .close-editor:hover{color:var(--text);background:rgba(255,255,255,.06);border-color:#2b3a5b}
  .close-editor:focus{outline:none;box-shadow:0 0 0 2px rgba(111,156,255,.25)}
  .suggestion-fields textarea{width:100%;height:100%;display:block;color:var(--text);font:12.5px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, "Liberation Mono", monospace}
  .code-editor{display:grid;grid-template-columns:56px 1fr;background:#0b1429;border:1px solid #2d416d;border-radius:10px;overflow:hidden;box-shadow:inset 0 0 0 1px rgba(19,33,58,.4);min-height:220px}
  .code-editor .line-nums{background:#0a1223;color:#5f7195;font:12px/1.35 ui-monospace, SFMono-Regular, Menlo, Monaco, "Liberation Mono", monospace;padding:12px 8px;text-align:right;user-select:none;display:flex;flex-direction:column;gap:2px;overflow:hidden;align-self:stretch}
  .code-editor .line-nums span{display:block}
  .code-editor textarea{background:transparent;border:none;resize:vertical;padding:12px 14px 12px 10px;line-height:1.4;tab-size:2;min-height:220px;height:100%;width:100%;box-sizing:border-box;color:var(--text);white-space:pre;overflow:auto}
  .code-editor textarea:disabled{color:#6d7c9c}
  .code-editor textarea:focus{outline:none}
  .code-editor textarea::-webkit-scrollbar{width:10px}
  .code-editor textarea::-webkit-scrollbar-thumb{background:#1f3155;border-radius:999px}
  .file-stage{display:flex;gap:32px;position:relative}
  .code-column{flex:1.15;min-width:0;overflow:auto}
  .comment-column{width:340px;flex:0 0 340px;position:relative}
  .thread-list{display:flex;flex-direction:column;gap:14px;position:relative;padding-right:0}
  .comment-thread{position:relative;padding-left:18px}
  .comment-thread .right{float:none;margin-left:auto}
  .comment-thread .badge.edit{margin-left:6px}
  .comment-pin{position:absolute;left:0;top:16px;width:12px;height:12px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 2px rgba(111,156,255,.4)}
  .comment-thread::before{content:"";position:absolute;left:5px;top:-22px;bottom:-22px;border-left:1px dashed rgba(111,156,255,.35)}
  .comment-thread:first-of-type::before{top:16px}
  .comment-thread:last-of-type::before{bottom:16px}
  .comment-thread.resolved .comment-pin{background:#1cd38a;box-shadow:0 0 0 2px rgba(28,211,138,.35)}
  .comment-thread.resolved{opacity:.82}
  .comment-thread.active{border-color:var(--accent);box-shadow:0 0 0 1px rgba(111,156,255,.45),0 12px 28px rgba(39,68,126,.35)}
  .comment-composer{position:sticky;top:72px;border-style:dashed;background:rgba(17,31,66,.88);backdrop-filter:blur(10px);z-index:2}
  .comment-composer.floating{position:absolute;top:auto;left:0;right:0}
  .composer-header{justify-content:space-between;position:relative;padding-right:36px}
  .row.compact{gap:8px}
  .share-bar{flex-wrap:wrap;justify-content:flex-end}
  .share-bar input{width:100%;max-width:420px}
  .primary-button{display:inline-block;padding:10px 18px;border-radius:12px;background:linear-gradient(180deg,#3b66ff,#1f3fd6);border:1px solid #3c5ee0;box-shadow:0 8px 18px rgba(63,110,255,.28);color:var(--text);font-weight:600}
  .primary-button:hover{text-decoration:none}
  .primary-button:focus{outline:none;box-shadow:0 0 0 2px rgba(111,156,255,.35),0 8px 18px rgba(63,110,255,.28)}
  .share-bar .primary-button{width:auto}
  @media (max-width:1100px){
    .wrap{padding:24px 16px}
    .file-stage{flex-direction:column}
    .comment-column{width:100%;flex:auto}
    .thread-list{position:static}
    .comment-thread::before{display:none}
    .comment-pin{display:none}
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="align-items:center">
      <div class="grow"><h1><a href="<?= h($_SERVER['PHP_SELF'] ?? '/') ?>"><?= h(APP_NAME) ?></a></h1><div class="muted">Paste code → share link → collect per-line comments. Perfect for Trello attachments.</div></div>
      <?php if ($doc): ?>
        <div><span class="badge <?= $doc['resolved'] ? 'ok' : '' ?>">Status: <?= $doc['resolved'] ? 'Resolved' : 'Open' ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($err): ?>
  <div class="card" style="border-color:#5b2a2a"><strong>Error:</strong> <?= h($err) ?></div>
  <?php endif; ?>

  <?php if (!$doc): ?>
  <form class="card" method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="create"/>
    <div class="row">
      <div class="grow"><label>Title<br><input name="title" placeholder="e.g., Review: refactor PlantController::ApplyQueuedTreatments" required></label></div>
      <div><label>Your name<br><input name="author" placeholder="Optional"></label></div>
      <div style="min-width:180px"><label>Language (hint)<br>
        <select name="language">
          <option value="auto">Auto</option>
          <option>c</option><option>cpp</option><option>csharp</option><option>go</option><option>java</option><option>js</option><option>ts</option><option>php</option><option>python</option><option>ruby</option><option>swift</option><option>kotlin</option><option>rust</option><option>sql</option><option>bash</option><option>html</option><option>css</option>
        </select>
      </label></div>
    </div>

    <div class="space"></div>
    <h2>Files</h2>
    <div id="files">
      <div class="file">
        <div class="row">
          <div class="grow"><input name="filename[]" placeholder="Filename (e.g., PlantController.cs)"></div>
          <div style="width:120px"><button type="button" onclick="addFile()">+ Add file</button></div>
        </div>
        <div class="space"></div>
        <textarea class="mono" name="content[]" rows="12" placeholder="Paste code here..."></textarea>
      </div>
    </div>

    <div class="space"></div>
    <button class="primary">Create shareable review link</button>
  </form>
  <?php else: ?>

  <div class="card">
    <div class="row" style="align-items:center">
      <div class="grow">
        <h2><?= h($doc['title']) ?></h2>
        <div class="muted">By <?= h($doc['author'] ?: 'Anonymous') ?> · Created <?= h($doc['created_at']) ?> · ID <?= h($doc['id']) ?></div>
      </div>
      <div class="flex share-bar">
        <a class="primary-button" href="<?= h($_SERVER['PHP_SELF'] ?? '/') ?>">Go to homepage</a>
        <span class="pill">Share this link:</span>
        <input readonly value="<?= h((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" onclick="this.select()"/>
      </div>
    </div>
  </div>

  <?php foreach ($doc['files'] as $fi => $f):
    $lines = explode("\n", $f['content']);
    $fileComments = $commentsByFile[$fi]['all'] ?? [];
    $openComments = $commentsByFile[$fi]['open'] ?? 0;
    $language = language_hint_for($f['name'], $doc['language'] ?? '');
  ?>
    <div class="card">
      <div class="sticky flex">
        <strong><?= h($f['name']) ?></strong>
        <span class="pill mono">Lines: <?= count($lines) ?></span>
        <span class="pill mono">Open comments: <?= $openComments ?></span>
      </div>
      <div class="file-stage" data-file="<?= $fi ?>">
        <div class="code-column">
          <ol class="mono linenos file-lines" id="file<?= $fi ?>" data-lang="<?= h($language) ?>">
            <?php foreach ($lines as $ln => $text):
              $ln1 = $ln + 1;
              $lineId = 'f' . $fi . 'l' . $ln1;
              $rawLine = rtrim($text, "\r");
            ?>
              <li id="<?= h($lineId) ?>" class="code-line"
                  data-file="<?= $fi ?>"
                  data-line="<?= $ln1 ?>"
                  data-line-id="<?= h($lineId) ?>"
                  data-raw="<?= h($rawLine) ?>"><span><?= h($rawLine) ?></span></li>
            <?php endforeach; ?>
          </ol>
        </div>
        <aside class="comment-column">
          <div class="thread-list" id="comment-rail-<?= $fi ?>">
            <form class="comment comment-composer" method="post" data-file="<?= $fi ?>">
              <input type="hidden" name="action" value="comment"/>
              <input type="hidden" name="id" value="<?= h($doc['id']) ?>"/>
              <input type="hidden" name="file" value="<?= $fi ?>"/>
              <input type="hidden" name="line" value="1"/>
              <input type="hidden" name="start_line" value="1"/>
              <input type="hidden" name="end_line" value="1"/>
              <input type="hidden" name="kind" value="comment"/>
              <input type="hidden" name="suggestion" value=""/>
              <div class="flex composer-header" style="position:relative">
                <div class="muted">New comment</div>
                <button type="button" class="close-editor" data-file="<?= $fi ?>" style="display:none" aria-label="Close">×</button>
              </div>
              <div class="space"></div>
              <div class="row compact">
                <div class="grow"><input name="who" placeholder="Your name (optional)"></div>
                <div style="width:120px"><input class="line-display" value="Line 1" readonly></div>
              </div>
              <div class="space"></div>
              <div class="mode-toggle">
                <label><input type="radio" name="mode<?= $fi ?>" value="comment" checked> Comment</label>
                <label><input type="radio" name="mode<?= $fi ?>" value="edit"> Suggest edit</label>
              </div>
              <div class="space"></div>
              <textarea name="body" rows="4" placeholder="Share context or feedback"></textarea>
              <div class="space"></div>
            <div class="suggestion-fields" style="display:none">
              <div class="code-editor">
                <div class="line-nums"></div>
                <textarea class="mono" name="suggestion_textarea" rows="6" placeholder="Proposed code changes"></textarea>
              </div>
              <div class="muted" style="margin-top:6px">Tip: adjust the snippet before submitting. Leave blank to cancel the edit.</div>
            </div>
              <div class="space"></div>
              <button class="primary submit-button">Submit</button>
            </form>

            <?php foreach ($fileComments as $c):
              $lineStart = max(1, (int)($c['line'] ?? 1));
              $lineEnd = max($lineStart, (int)($c['line_end'] ?? $lineStart));
              $lineId = 'f' . $fi . 'l' . $lineStart;
              $lineLabel = $lineEnd > $lineStart ? "Lines $lineStart-$lineEnd" : "Line $lineStart";
              $isEdit = ($c['kind'] ?? 'comment') === 'edit';
            ?>
              <div class="comment comment-thread<?= !empty($c['resolved']) ? ' resolved' : '' ?>" data-file="<?= $fi ?>" data-line-start="<?= $lineStart ?>" data-line-end="<?= $lineEnd ?>">
                <div class="comment-pin"></div>
                <div class="small">
                  <a href="#<?= h($lineId) ?>" onclick="return scrollToLine(<?= $fi ?>, <?= $lineStart ?>, <?= $lineEnd ?>)"><?= h($lineLabel) ?></a>
                  · <strong><?= h($c['who']) ?></strong>
                  · <span class="muted"><?= h($c['created_at']) ?></span>
                  <?php if ($isEdit): ?><span class="badge edit">edit</span><?php endif; ?>
                  <?php if ($c['resolved']): ?><span class="badge ok right">resolved</span><?php endif; ?>
                </div>
                <?php $body = trim((string)($c['body'] ?? '')); if ($body !== ''): ?>
                  <div><?= nl2br(h($body)) ?></div>
                <?php endif; ?>
                <?php $suggest = trim((string)($c['suggestion'] ?? '')); if ($suggest !== ''): ?>
                  <div class="suggestion">
                    <div class="small muted">Suggested edit:</div>
                    <pre class="mono" style="margin:6px 0 0;white-space:pre-wrap;"><?= h($suggest) ?></pre>
                  </div>
                <?php endif; ?>
                <?php if (is_admin() && !$c['resolved']): ?>
                  <div class="space"></div>
                  <a class="badge" href="?action=resolve&id=<?= h($doc['id']) ?>&cid=<?= h($c['id']) ?>&admin=<?= h($_GET['admin'] ?? '') ?>">Mark resolved</a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </aside>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <div class="flex">
      <?php if (is_admin()): ?>
        <a class="badge" href="?action=resolve&id=<?= h($doc['id']) ?>&admin=<?= h($_GET['admin'] ?? '') ?>">Toggle Resolved</a>
        <a class="badge" style="color:#ff9595;border-color:#ff9595" href="?action=delete&id=<?= h($doc['id']) ?>&admin=<?= h($_GET['admin'] ?? '') ?>" onclick="return confirm('Delete this review permanently?')">Delete</a>
      <?php endif; ?>
      <span class="muted">Tip: click or drag on code to select lines, then choose comment or suggest edit.</span>
    </div>
  </div>

<?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
const decodeHtml = (() => {
  const textarea = document.createElement('textarea');
  return (str) => {
    textarea.innerHTML = str;
    return textarea.value;
  };
})();

function addFile(){
  const wrap = document.getElementById('files');
  const div = document.createElement('div');
  div.className = 'file';
  div.innerHTML = `
    <div class="row">
      <div class="grow"><input name="filename[]" placeholder="Filename"></div>
      <div style="width:120px"></div>
    </div>
    <div class="space"></div>
    <textarea class="mono" name="content[]" rows="12" placeholder="Paste code here..."></textarea>
  `;
  wrap.appendChild(div);
}

function applyHighlighting(){
  if (typeof hljs === 'undefined') { return; }
  document.querySelectorAll('.linenos').forEach(ol => {
    const spans = Array.from(ol.querySelectorAll('li span'));
    if (!spans.length) { return; }
    const original = spans.map(span => span.textContent).join('\n');
    const language = ol.dataset.lang || undefined;
    let highlighted;
    try {
      highlighted = language ? hljs.highlight(original, {language}).value : hljs.highlightAuto(original).value;
    } catch (e) {
      highlighted = hljs.highlightAuto(original).value;
    }
    const rawLines = original.split('\n');
    const pieces = highlighted.split(/\n/);
    const escapeHtml = typeof hljs.escapeHTML === 'function'
      ? hljs.escapeHTML
      : (str) => {
          const ENTITIES = {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;"};
          return str.replace(/[&<>"']/g, ch => ENTITIES[ch]);
        };
    if (pieces.length < rawLines.length) {
      for (let i = pieces.length; i < rawLines.length; i++) {
        pieces[i] = escapeHtml(rawLines[i]);
      }
    }
    spans.forEach((span, idx) => {
      span.innerHTML = pieces[idx] ?? '';
    });
  });
}

applyHighlighting();

const state = {
  selecting: false,
  activeFile: null,
  startLine: null,
  endLine: null,
};

const forms = new Map();
const suggestionEditors = new WeakMap();

document.querySelectorAll('form.comment').forEach(form => {
  const file = form.dataset.file;
  if (!file) { return; }
  forms.set(file, form);
  setupForm(form);
});

document.querySelectorAll('.code-line').forEach(line => {
  line.addEventListener('mousedown', onLineMouseDown);
  line.addEventListener('mouseenter', onLineMouseEnter);
  line.addEventListener('mouseup', onLineMouseUp);
  line.addEventListener('click', e => e.preventDefault());
  // Mark overflowing lines to show horizontal scrollbar only when needed
  if (line.scrollWidth > line.clientWidth + 2) {
    line.classList.add('overflowing');
  }
});

document.addEventListener('mouseup', () => {
  if (!state.selecting) { return; }
  state.selecting = false;
  if (state.activeFile === null) { return; }
  finalizeSelection();
});

handleInitialHash();
window.addEventListener('hashchange', handleInitialHash);
window.addEventListener('resize', markOverflowingLinesThrottled, {passive:true});

function markOverflowingLines(){
  document.querySelectorAll('.code-line').forEach(line => {
    if (line.scrollWidth > line.clientWidth + 2) {
      line.classList.add('overflowing');
    } else {
      line.classList.remove('overflowing');
    }
  });
}

let __markOverflowTimer;
function markOverflowingLinesThrottled(){
  if (__markOverflowTimer) { cancelAnimationFrame(__markOverflowTimer); }
  __markOverflowTimer = requestAnimationFrame(() => {
    markOverflowingLines();
    __markOverflowTimer = null;
  });
}

function onLineMouseDown(event){
  event.preventDefault();
  const li = event.currentTarget;
  const file = String(li.dataset.file);
  const line = parseInt(li.dataset.line, 10) || 1;
  state.selecting = true;
  state.activeFile = file;
  state.startLine = line;
  state.endLine = line;
  updateSelection(file, line, line);
}

function onLineMouseEnter(event){
  if (!state.selecting) { return; }
  const li = event.currentTarget;
  const file = String(li.dataset.file);
  if (file !== state.activeFile) { return; }
  const line = parseInt(li.dataset.line, 10) || 1;
  state.endLine = line;
  updateSelection(file, state.startLine, line);
}

function onLineMouseUp(event){
  if (!state.selecting) { return; }
  const li = event.currentTarget;
  const file = String(li.dataset.file);
  if (file !== state.activeFile) { return; }
  const line = parseInt(li.dataset.line, 10) || 1;
  state.endLine = line;
  finalizeSelection();
  state.selecting = false;
}

function finalizeSelection(){
  if (state.activeFile === null || state.startLine === null || state.endLine === null) {
    return;
  }
  const file = String(state.activeFile);
  updateSelection(file, state.startLine, state.endLine);
  const form = forms.get(file);
  if (form) {
    openCommentPanel(form, file, Math.min(state.startLine, state.endLine), Math.max(state.startLine, state.endLine));
  }
  // Help users with long lines: horizontally reveal content start
  const targetLine = Math.min(state.startLine, state.endLine);
  maybeScrollLineHorizontally(file, targetLine);
}

function updateSelection(file, start, end){
  const key = String(file);
  const [min, max] = start <= end ? [start, end] : [end, start];
  highlightRange(key, min, max);
  const form = forms.get(key);
  if (form) {
    updateFormSelection(form, key, min, max);
  }
}

function highlightRange(file, start, end){
  const fileId = String(file);
  document.querySelectorAll('.linenos li').forEach(li => li.classList.remove('hl'));
  for (let ln = start; ln <= end; ln++) {
    const id = `f${fileId}l${ln}`;
    const node = document.getElementById(id);
    if (node) {
      node.classList.add('hl');
    }
  }

  document.querySelectorAll('.comment-thread').forEach(node => node.classList.remove('active'));
  document.querySelectorAll(`.comment-thread[data-file="${fileId}"]`).forEach(node => {
    const cStart = parseInt(node.dataset.lineStart || '1', 10);
    const cEnd = parseInt(node.dataset.lineEnd || cStart, 10);
    if (cStart <= end && cEnd >= start) {
      node.classList.add('active');
    }
  });
}

function updateFormSelection(form, file, start, end){
  const fileId = String(file);
  form.querySelector('input[name="file"]').value = file;
  form.querySelector('input[name="line"]').value = start;
  form.querySelector('input[name="start_line"]').value = start;
  form.querySelector('input[name="end_line"]').value = end;
  const display = form.querySelector('.line-display');
  if (display) {
    display.value = start === end ? `Line ${start}` : `Lines ${start}-${end}`;
  }
  const closeButton = form.querySelector('.close-editor');
  if (closeButton) {
    closeButton.style.display = 'inline-flex';
  }
  if (isEditMode(form)) {
    const suggestionTextarea = form.querySelector('textarea[name="suggestion_textarea"]');
    const suggestionHidden = form.querySelector('input[name="suggestion"]');
    if (suggestionTextarea) { suggestionTextarea.dataset.userEdited = 'false'; }
    if (suggestionHidden) { suggestionHidden.value = ''; }
    prefillSuggestion(form, fileId, start, end);
  }
}

function gatherCode(file, start, end){
  const fileId = String(file);
  const lines = [];
  for (let ln = start; ln <= end; ln++) {
    const node = document.getElementById(`f${fileId}l${ln}`);
    if (node) {
      lines.push(decodeHtml(node.getAttribute('data-raw') || ''));
    }
  }
  return lines.join('\n');
}

function prefillSuggestion(form, file, start, end){
  const textarea = form.querySelector('textarea[name="suggestion_textarea"]');
  const hidden = form.querySelector('input[name="suggestion"]');
  if (!textarea || !hidden) { return; }
  prepareSuggestionEditor(textarea);
  const code = gatherCode(file, start, end);
  if (!code) {
    refreshSuggestionEditor(textarea);
    return;
  }
  if (!textarea.dataset.userEdited || textarea.dataset.userEdited === 'false') {
    textarea.value = code;
    hidden.value = code;
    textarea.dataset.userEdited = 'false';
  }
  refreshSuggestionEditor(textarea);
}

function prepareSuggestionEditor(textarea){
  if (!textarea || suggestionEditors.has(textarea)) { return; }
  const editor = textarea.closest('.code-editor');
  if (!editor) { return; }
  const gutter = editor.querySelector('.line-nums');
  if (!gutter) { return; }

  const update = () => {
    const value = textarea.value || '';
    const lines = Math.max(1, value.split('\n').length);
    let html = '';
    for (let i = 1; i <= lines; i++) {
      html += `<span>${i}</span>`;
    }
    gutter.innerHTML = html;
    gutter.scrollTop = textarea.scrollTop;
    // Ensure textarea height expands to fit content up to a sensible max
    textarea.style.height = 'auto';
    const borderPad = 24; // approximate padding
    textarea.style.height = Math.min(520, textarea.scrollHeight + borderPad) + 'px';
  };

  textarea.addEventListener('input', update);
  textarea.addEventListener('scroll', () => {
    gutter.scrollTop = textarea.scrollTop;
  });

  update();
  suggestionEditors.set(textarea, update);
}

function refreshSuggestionEditor(textarea){
  if (!textarea) { return; }
  const updater = suggestionEditors.get(textarea);
  if (updater) {
    updater();
  }
}

function setupForm(form){
  const suggestionWrapper = form.querySelector('.suggestion-fields');
  const suggestionHidden = form.querySelector('input[name="suggestion"]');
  const suggestionTextarea = form.querySelector('textarea[name="suggestion_textarea"]');
  const kindHidden = form.querySelector('input[name="kind"]');
  const commentTextarea = form.querySelector('textarea[name="body"]');
  const closeButton = form.querySelector('.close-editor');

  if (suggestionTextarea) {
    suggestionTextarea.disabled = true;
    suggestionTextarea.dataset.userEdited = 'false';
    suggestionTextarea.addEventListener('input', () => {
      suggestionTextarea.dataset.userEdited = 'true';
      if (suggestionHidden) {
        suggestionHidden.value = suggestionTextarea.value;
      }
      refreshSuggestionEditor(suggestionTextarea);
    });
    prepareSuggestionEditor(suggestionTextarea);
  }

  if (closeButton) {
    closeButton.addEventListener('click', () => {
      resetForm(form);
    });
  }

  form.querySelectorAll('.mode-toggle input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', () => {
      if (!radio.checked) { return; }
      const mode = radio.value === 'edit' ? 'edit' : 'comment';
      if (kindHidden) {
        kindHidden.value = mode;
      }
      if (mode === 'edit') {
        if (suggestionWrapper) { suggestionWrapper.style.display = 'block'; }
        if (suggestionTextarea) {
          suggestionTextarea.disabled = false;
          suggestionTextarea.dataset.userEdited = suggestionTextarea.value ? 'true' : 'false';
        }
        const file = String(form.dataset.file);
        const start = parseInt(form.querySelector('input[name="start_line"]').value, 10) || 1;
        const end = parseInt(form.querySelector('input[name="end_line"]').value, 10) || start;
        prefillSuggestion(form, file, start, end);
      } else {
        if (suggestionWrapper) { suggestionWrapper.style.display = 'none'; }
        if (suggestionTextarea) {
          suggestionTextarea.disabled = true;
          suggestionTextarea.value = '';
          suggestionTextarea.dataset.userEdited = 'false';
        }
        if (suggestionHidden) {
          suggestionHidden.value = '';
        }
        if (suggestionTextarea) {
          refreshSuggestionEditor(suggestionTextarea);
        }
      }
    });
  });

  form.addEventListener('submit', () => {
    if (suggestionTextarea && suggestionTextarea.disabled) {
      suggestionTextarea.value = '';
    }
  });

  if (commentTextarea) {
    commentTextarea.addEventListener('focus', () => {
      commentTextarea.dataset.userTouched = 'true';
    });
  }
}

function resetForm(form){
  const file = form.dataset.file;
  form.querySelector('input[name="line"]').value = 1;
  form.querySelector('input[name="start_line"]').value = 1;
  form.querySelector('input[name="end_line"]').value = 1;
  const display = form.querySelector('.line-display');
  if (display) { display.value = 'Line 1'; }
  const commentTextarea = form.querySelector('textarea[name="body"]');
  if (commentTextarea) { commentTextarea.value = ''; }
  const suggestionHidden = form.querySelector('input[name="suggestion"]');
  if (suggestionHidden) { suggestionHidden.value = ''; }
  const suggestionTextarea = form.querySelector('textarea[name="suggestion_textarea"]');
  if (suggestionTextarea) {
    suggestionTextarea.value = '';
    suggestionTextarea.disabled = true;
    suggestionTextarea.dataset.userEdited = 'false';
    refreshSuggestionEditor(suggestionTextarea);
  }
  const commentModeRadio = form.querySelector('.mode-toggle input[value="comment"]');
  if (commentModeRadio) {
    commentModeRadio.checked = true;
  }
  const kindHidden = form.querySelector('input[name="kind"]');
  if (kindHidden) {
    kindHidden.value = 'comment';
  }
  const suggestionWrapper = form.querySelector('.suggestion-fields');
  if (suggestionWrapper) { suggestionWrapper.style.display = 'none'; }
  const closeButton = form.querySelector('.close-editor');
  if (closeButton) { closeButton.style.display = 'none'; }
  unsetFloatingComposer(form);
  clearHighlights();
  state.activeFile = null;
  state.startLine = null;
  state.endLine = null;
}

function clearHighlights(){
  document.querySelectorAll('.linenos li').forEach(li => li.classList.remove('hl'));
  document.querySelectorAll('.comment-thread').forEach(node => node.classList.remove('active'));
}

function isEditMode(form){
  return (form.querySelector('input[name="kind"]').value || 'comment') === 'edit';
}

function openCommentPanel(form, file, start, end){
  const commentTextarea = form.querySelector('textarea[name="body"]');
  if (commentTextarea) {
    try {
      commentTextarea.focus({preventScroll:true});
    } catch (err) {
      commentTextarea.focus();
    }
  }
  form.querySelectorAll('.mode-toggle input[type="radio"]').forEach(radio => {
    if (radio.value === 'edit' && radio.checked) {
      prefillSuggestion(form, file, start, end);
    }
  });
  positionComposerNearSelection(form, file, start);
}

function scrollToLine(file, start, end){
  const fileId = String(file);
  const node = document.getElementById(`f${fileId}l${start}`);
  if (node) {
    node.scrollIntoView({behavior:'smooth', block:'center'});
    const last = end || start;
    state.activeFile = fileId;
    state.startLine = start;
    state.endLine = last;
    updateSelection(fileId, start, last);
    // Adjust horizontal scroll for long lines
    maybeScrollLineHorizontally(fileId, start);
  }
  return false;
}

function handleInitialHash(){
  const hash = decodeURIComponent(window.location.hash || '').replace(/^#/, '');
  if (!hash) { return; }
  let match = hash.match(/^f(\d+)l(\d+)$/);
  if (match) {
    scrollToLine(match[1], parseInt(match[2], 10) || 1);
    return;
  }
  match = hash.match(/^l(\d+)$/);
  if (match) {
    const line = parseInt(match[1], 10) || 1;
    const node = document.querySelector(`.code-line[data-line="${line}"]`);
    if (node) {
      scrollToLine(node.dataset.file, line);
    }
  }
}

// --- Floating composer positioning -----------------------------------------
function positionComposerNearSelection(form, file, start){
  try {
    const fileId = String(file);
    const lineEl = document.getElementById(`f${fileId}l${start}`);
    const rail = document.getElementById(`comment-rail-${fileId}`);
    if (!lineEl || !rail) { return; }

    if (!form.classList.contains('floating')) {
      form.classList.add('floating');
    }

    const lineRect = lineEl.getBoundingClientRect();
    const stage = lineEl.closest('.file-stage');
    const stageRect = stage ? stage.getBoundingClientRect() : null;
    const withinStage = stageRect ? (lineRect.top - stageRect.top) : 0;
    const topPx = Math.max(0, (rail.scrollTop || 0) + withinStage - 10);
    form.style.top = `${topPx}px`;

    ensureFloatingHandlers(form, fileId);
  } catch (e) {
    unsetFloatingComposer(form);
  }
}

function ensureFloatingHandlers(form, fileId){
  const key = `__floatingHandlers_${fileId}`;
  if (form[key]) { return; }
  const updater = () => {
    const start = parseInt(form.querySelector('input[name="start_line"]').value, 10) || 1;
    positionComposerNearSelection(form, fileId, start);
  };
  const rail = document.getElementById(`comment-rail-${fileId}`);
  const codeOl = document.getElementById(`file${fileId}`);
  const listeners = [];
  if (rail) {
    const fn = () => requestAnimationFrame(updater);
    rail.addEventListener('scroll', fn, {passive:true});
    listeners.push(['scroll', rail, fn]);
  }
  if (codeOl) {
    const fn2 = () => requestAnimationFrame(updater);
    codeOl.addEventListener('scroll', fn2, {passive:true});
    listeners.push(['scroll', codeOl, fn2]);
  }
  const onResize = () => requestAnimationFrame(updater);
  window.addEventListener('resize', onResize);
  listeners.push(['resize', window, onResize]);
  form[key] = listeners;
}

function unsetFloatingComposer(form){
  form.classList.remove('floating');
  form.style.top = '';
  const fileId = String(form.dataset.file || '');
  const key = `__floatingHandlers_${fileId}`;
  const listeners = form[key];
  if (listeners && Array.isArray(listeners)) {
    listeners.forEach(([type, target, fn]) => {
      try { target.removeEventListener(type, fn); } catch (_) {}
    });
  }
  form[key] = null;
}

// --- Long line horizontal scroll assistance --------------------------------
function maybeScrollLineHorizontally(file, line){
  const fileId = String(file);
  const li = document.getElementById(`f${fileId}l${line}`);
  if (!li) { return; }
  // Only if content overflows
  if (li.scrollWidth <= li.clientWidth + 2) { return; }

  const raw = li.getAttribute('data-raw') || '';
  const firstIdx = getFirstNonWhitespaceIndex(raw);
  const charWidth = measureMonoCharWidth(li);
  const padChars = 2;
  const targetChars = Math.max(0, firstIdx - padChars);
  const targetLeft = Math.round(targetChars * charWidth);
  // Smooth-ish adjustment without jank
  li.scrollLeft = targetLeft;
}

function getFirstNonWhitespaceIndex(s){
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    if (ch !== ' ' && ch !== '\t') { return i; }
  }
  return 0;
}

function measureMonoCharWidth(referenceEl){
  const cacheKey = '__charWidthCache';
  if (!measureMonoCharWidth[cacheKey]) {
    const probe = document.createElement('span');
    // 100 m's to average widths; use same font context
    probe.textContent = 'm'.repeat(100);
    probe.style.visibility = 'hidden';
    probe.style.whiteSpace = 'pre';
    referenceEl.appendChild(probe);
    const width = probe.getBoundingClientRect().width / 100;
    referenceEl.removeChild(probe);
    measureMonoCharWidth[cacheKey] = Math.max(1, width);
  }
  return measureMonoCharWidth[cacheKey];
}
</script>
</body>
</html>
