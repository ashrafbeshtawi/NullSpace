<?php
// run.php — execute an allowlisted maintenance script and stream its output.
//
// Guards:
//   - POST only
//   - CSRF token must match the session token issued by index.php
//   - Action must match a key in $ALLOWED; the script path is hardcoded
//     server-side so a request body cannot smuggle in an arbitrary path.

session_start();

$ALLOWED = [
    'deploy'          => '/opt/NullSpace/bin/deploy.sh',
    'backup-postgres' => '/opt/NullSpace/bin/backup-postgres.sh',
    'renew-certs'     => '/opt/NullSpace/bin/renew-certs.sh',
    'cleanup'         => '/opt/NullSpace/bin/cleanup.sh',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('method not allowed');
}

$token = $_POST['csrf'] ?? '';
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(403);
    exit('csrf check failed');
}

$action = $_POST['action'] ?? '';
if (!isset($ALLOWED[$action])) {
    http_response_code(400);
    exit('unknown action');
}

$script = $ALLOWED[$action];

// Stream output as it arrives; turn off buffering so the browser sees it live.
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { ob_end_flush(); }

?><!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>Running <?= htmlspecialchars($action) ?></title>
<style>
  body { background:#060609; color:#e2e8f0; font-family:-apple-system,BlinkMacSystemFont,sans-serif; padding:2rem; }
  h2 { font-size:1rem; font-weight:600; margin-bottom:1rem; }
  pre { background:#0c0c14; border:1px solid rgba(255,255,255,0.05); border-radius:10px;
        padding:1.25rem; white-space:pre-wrap; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        font-size:0.82rem; line-height:1.5; max-height:70vh; overflow:auto; }
  a { color:#60a5fa; text-decoration:none; }
  a:hover { text-decoration:underline; }
  .meta { color:#475569; font-size:0.75rem; margin-top:1rem; }
</style>
</head><body>
<h2>$ <?= htmlspecialchars($script) ?></h2>
<pre><?php
@flush();

$cmd = escapeshellarg($script) . ' 2>&1';
$fp = popen($cmd, 'r');
if (!is_resource($fp)) {
    echo "failed to start process\n";
} else {
    while (!feof($fp)) {
        $chunk = fread($fp, 4096);
        if ($chunk === false || $chunk === '') break;
        echo htmlspecialchars($chunk);
        @flush();
    }
    $status = pclose($fp);
    $exit = function_exists('pcntl_wexitstatus') && pcntl_wifexited($status)
        ? pcntl_wexitstatus($status)
        : $status;
}
?></pre>
<p class="meta">Exit code: <?= isset($exit) ? (int)$exit : 'n/a' ?></p>
<p><a href="/">&larr; Back to dashboard</a></p>
</body></html>
