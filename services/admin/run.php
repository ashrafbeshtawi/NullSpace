<?php
// run.php — execute an allowlisted maintenance script and stream its output.
//
// Guards:
//   - POST only
//   - CSRF token must match the session token issued by index.php
//   - Action must match a key in $ALLOWED; the script path is hardcoded
//     server-side so a request body cannot smuggle in an arbitrary path.
//   - Actions that take a snapshot id validate it against a strict regex
//     before passing it through to the script.

session_start();

// Each entry hardcodes the script + any fixed subcommand server-side.
// `needs_snapshot` means the action accepts a `snapshot` POST field that
// gets validated and passed to the script as its next argument. For those
// actions we also export NULLSPACE_RESTORE_YES=1 so restore-offsite.sh
// doesn't hang on stdin prompts when invoked from the web.
$ALLOWED = [
    'deploy'          => ['script' => '/opt/NullSpace/bin/deploy.sh'],
    'backup-postgres' => ['script' => '/opt/NullSpace/bin/backup-postgres.sh'],
    'renew-certs'     => ['script' => '/opt/NullSpace/bin/renew-certs.sh'],
    'cleanup'         => ['script' => '/opt/NullSpace/bin/cleanup.sh'],

    'restore-list'    => ['script' => '/opt/NullSpace/bin/restore-offsite.sh', 'subcommand' => 'list'],
    'restore-check'   => ['script' => '/opt/NullSpace/bin/restore-offsite.sh', 'subcommand' => 'check'],
    'restore-env'     => ['script' => '/opt/NullSpace/bin/restore-offsite.sh', 'subcommand' => 'env',  'needs_snapshot' => true],
    'restore-pg'      => ['script' => '/opt/NullSpace/bin/restore-offsite.sh', 'subcommand' => 'pg',   'needs_snapshot' => true],
    'restore-full'    => ['script' => '/opt/NullSpace/bin/restore-offsite.sh', 'subcommand' => 'full', 'needs_snapshot' => true],
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

$entry          = $ALLOWED[$action];
$script         = $entry['script'];
$subcommand     = $entry['subcommand'] ?? null;
$needs_snapshot = !empty($entry['needs_snapshot']);

$snapshot = '';
if ($needs_snapshot) {
    $snapshot = trim($_POST['snapshot'] ?? '');
    // restic snapshot ids are hex strings; 'latest' is the alias for "most
    // recent". Anything else is rejected before reaching the shell.
    if (!preg_match('/^(latest|[a-fA-F0-9]{4,64})$/', $snapshot)) {
        http_response_code(400);
        exit('invalid snapshot id (expected "latest" or hex)');
    }
}

// Build the command. Subcommand and snapshot are escaped even though both
// are constrained server-side — defence in depth.
$cmd = '';
if ($needs_snapshot) {
    // Skip the script's interactive `Type 'yes' to continue:` prompt.
    $cmd .= 'NULLSPACE_RESTORE_YES=1 ';
}
$cmd .= escapeshellarg($script);
if ($subcommand !== null) {
    $cmd .= ' ' . escapeshellarg($subcommand);
}
if ($needs_snapshot) {
    $cmd .= ' ' . escapeshellarg($snapshot);
}
$cmd .= ' 2>&1';

// Stream output as it arrives; turn off buffering so the browser sees it live.
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { ob_end_flush(); }

$display_cmd = $script
    . ($subcommand !== null ? ' ' . $subcommand : '')
    . ($needs_snapshot ? ' ' . $snapshot : '');

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
<h2>$ <?= htmlspecialchars($display_cmd) ?></h2>
<pre><?php
@flush();

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
