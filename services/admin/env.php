<?php
// env.php — view, edit, download and upload /opt/NullSpace/.env.
//
// The file is read and written through bin/env-file.sh via sudo (same path
// run.php uses), so it works whatever owner/mode the .env has on the host.
// Writes keep the previous version as .env.bak. Changes take effect for a
// service once it is recreated (e.g. via the Deploy button).

session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

const ENV_SCRIPT   = '/opt/NullSpace/bin/env-file.sh';
const MAX_ENV_SIZE = 1024 * 1024;

// Runs env-file.sh with the given subcommand; returns [exit code, stdout+stderr].
function env_file($subcommand, $stdin = '') {
    $cmd = 'sudo -n ' . escapeshellarg(ENV_SCRIPT) . ' ' . escapeshellarg($subcommand) . ' 2>&1';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [1, 'failed to start process'];
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), $out];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, $token)) {
        http_response_code(403);
        exit('csrf check failed');
    }

    $action = $_POST['action'] ?? '';
    $content = null;
    if ($action === 'save') {
        $content = $_POST['content'] ?? null;
    } elseif ($action === 'upload') {
        $file = $_FILES['file'] ?? null;
        if ($file && $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $content = file_get_contents($file['tmp_name']);
        } else {
            $_SESSION['env_flash'] = ['error', 'upload failed'];
        }
    } else {
        http_response_code(400);
        exit('unknown action');
    }

    if ($content !== null) {
        $content = str_replace("\r\n", "\n", $content);
        if (strlen($content) > MAX_ENV_SIZE) {
            $_SESSION['env_flash'] = ['error', 'file too large (max 1 MB)'];
        } elseif (strpos($content, "\0") !== false || !mb_check_encoding($content, 'UTF-8')) {
            $_SESSION['env_flash'] = ['error', 'not a text file'];
        } else {
            [$code, $out] = env_file('write', $content);
            $_SESSION['env_flash'] = [$code === 0 ? 'ok' : 'error', trim($out)];
        }
    }

    header('Location: /env.php', true, 303);
    exit;
}

[$read_code, $env] = env_file('read');

if (isset($_GET['download'])) {
    if ($read_code !== 0) {
        http_response_code(500);
        exit($env);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename=".env"');
    header('Cache-Control: no-store');
    echo $env;
    exit;
}

$flash = $_SESSION['env_flash'] ?? null;
unset($_SESSION['env_flash']);
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>.env — NullSpace Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/properties/properties.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #060609;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 2rem;
        }
        .wrap { max-width: 1100px; margin: 0 auto; }
        a { color: #60a5fa; text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 1rem 0 0.35rem; }
        .hint { color: #475569; font-size: 0.8rem; margin-bottom: 1.25rem; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-bottom: 0.75rem; align-items: center; }
        .btn {
            background: #0c0c14;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 0.55rem 1rem;
            color: #e2e8f0;
            font: inherit;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { background: #10101a; border-color: rgba(255, 255, 255, 0.15); text-decoration: none; }
        .btn-primary { border-color: rgba(52, 211, 153, 0.35); color: #34d399; }
        .btn[hidden] { display: none; }
        .flash { border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.85rem; white-space: pre-wrap; }
        .flash-ok { background: rgba(52, 211, 153, 0.08); color: #34d399; }
        .flash-error { background: rgba(248, 113, 113, 0.08); color: #f87171; }
        .upload { display: inline-flex; gap: 0.5rem; align-items: center; margin: 0; }
        .upload input[type=file] { font-size: 0.8rem; color: #94a3b8; }

        .CodeMirror {
            height: 70vh;
            background: #0c0c14;
            color: #e2e8f0;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.82rem;
        }
        .CodeMirror.editing { border-color: rgba(52, 211, 153, 0.35); }
        .CodeMirror-gutters { background: #0c0c14; border-right: 1px solid rgba(255, 255, 255, 0.05); }
        .CodeMirror-linenumber { color: #334155; }
        .CodeMirror-cursor { border-left-color: #e2e8f0; }
        .CodeMirror-selected, .CodeMirror-focused .CodeMirror-selected { background: rgba(96, 165, 250, 0.2); }
        .cm-s-default .cm-def { color: #60a5fa; }
        .cm-s-default .cm-quote { color: #34d399; }
        .cm-s-default .cm-comment { color: #475569; font-style: italic; }
        .cm-s-default .cm-header { color: #a78bfa; }
    </style>
</head>
<body>
<div class="wrap">
    <a href="/">&larr; Back to dashboard</a>
    <h1>.env</h1>
    <p class="hint">/opt/NullSpace/.env — saving keeps the previous version as .env.bak. Services pick up changes when recreated (Deploy).</p>

    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash[1]) ?></div>
    <?php endif; ?>
    <?php if ($read_code !== 0): ?>
    <div class="flash flash-error">could not read .env: <?= htmlspecialchars(trim($env)) ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <button type="button" class="btn" id="edit-btn">Edit</button>
        <button type="button" class="btn" id="cancel-btn" hidden>Cancel</button>
        <button type="submit" class="btn btn-primary" id="save-btn" form="save-form" hidden>Save</button>
        <a class="btn" href="/env.php?download=1">Download</a>
        <form method="POST" action="/env.php" enctype="multipart/form-data" class="upload"
              onsubmit="return confirm('Replace .env with the uploaded file?')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="file" required>
            <button type="submit" class="btn">Upload</button>
        </form>
    </div>

    <form method="POST" action="/env.php" id="save-form"
          onsubmit="return confirm('Overwrite .env with the edited content?')">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <textarea name="content" id="content"><?= htmlspecialchars($read_code === 0 ? $env : '') ?></textarea>
    </form>
</div>
<script>
    const editor = CodeMirror.fromTextArea(document.getElementById('content'), {
        mode: 'properties',
        lineNumbers: true,
        readOnly: true,
    });
    const original = editor.getValue();
    const editBtn = document.getElementById('edit-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const saveBtn = document.getElementById('save-btn');

    function setEditing(isEditing) {
        editor.setOption('readOnly', !isEditing);
        editor.getWrapperElement().classList.toggle('editing', isEditing);
        editBtn.hidden = isEditing;
        cancelBtn.hidden = saveBtn.hidden = !isEditing;
        if (isEditing) editor.focus();
    }
    editBtn.addEventListener('click', () => setEditing(true));
    cancelBtn.addEventListener('click', () => { editor.setValue(original); setEditing(false); });
</script>
</body>
</html>
