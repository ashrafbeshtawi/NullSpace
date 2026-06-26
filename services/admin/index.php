<?php
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// Host stats. Disk comes from a bind-mounted host path (statfs returns the
// host filesystem). CPU + RAM read from /host/proc (read-only bind of /proc).
// All readers return null on failure so the UI can render "N/A" instead of
// crashing when run outside the expected container layout.

function host_disk_stats($path = '/opt/NullSpace') {
    $total = @disk_total_space($path);
    $free  = @disk_free_space($path);
    if ($total === false || $free === false || $total <= 0) return null;
    $used = $total - $free;
    return [
        'used'  => $used,
        'total' => $total,
        'pct'   => round(($used / $total) * 100, 1),
    ];
}

function host_memory_stats() {
    $meminfo = @file_get_contents('/host/proc/meminfo');
    if ($meminfo === false) return null;
    if (!preg_match('/MemTotal:\s+(\d+)/',     $meminfo, $t)) return null;
    if (!preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $a)) return null;
    $total = ((int) $t[1]) * 1024; // kB -> bytes
    $avail = ((int) $a[1]) * 1024;
    $used  = $total - $avail;
    if ($total <= 0) return null;
    return [
        'used'  => $used,
        'total' => $total,
        'pct'   => round(($used / $total) * 100, 1),
    ];
}

function read_cpu_stat() {
    $raw = @file_get_contents('/host/proc/stat');
    if ($raw === false) return null;
    if (!preg_match('/^cpu\s+(.+)/', $raw, $m)) return null;
    $vals = array_map('intval', preg_split('/\s+/', trim($m[1])));
    // user, nice, system, idle, iowait, irq, softirq, steal, guest, guest_nice
    $idle  = ($vals[3] ?? 0) + ($vals[4] ?? 0);
    $total = array_sum($vals);
    return ['idle' => $idle, 'total' => $total];
}

function host_cpu_stats() {
    $a = read_cpu_stat();
    if ($a === null) return null;
    usleep(100000); // 100ms sample window
    $b = read_cpu_stat();
    if ($b === null) return null;
    $idle_diff  = $b['idle']  - $a['idle'];
    $total_diff = $b['total'] - $a['total'];
    if ($total_diff <= 0) return ['pct' => 0.0, 'cores' => host_cpu_cores()];
    $pct = (1 - $idle_diff / $total_diff) * 100;
    return [
        'pct'   => round(max(0, min(100, $pct)), 1),
        'cores' => host_cpu_cores(),
    ];
}

function host_cpu_cores() {
    $raw = @file_get_contents('/host/proc/cpuinfo');
    if ($raw === false) return null;
    return preg_match_all('/^processor\s*:/m', $raw);
}

function format_bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function pct_tone($pct) {
    if ($pct === null) return 'low';
    if ($pct < 60) return 'low';
    if ($pct < 85) return 'mid';
    return 'high';
}

$stats = [
    'disk'   => host_disk_stats(),
    'memory' => host_memory_stats(),
    'cpu'    => host_cpu_stats(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NullSpace Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #060609;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 2rem;
        }
        .header {
            text-align: center;
            margin-bottom: 3rem;
        }
        .header h1 {
            font-size: 1.75rem;
            font-weight: 800;
            color: #f8fafc;
            letter-spacing: -0.03em;
        }
        .header p {
            color: #475569;
            margin-top: 0.35rem;
            font-size: 0.85rem;
        }
        .env-badge {
            display: inline-block;
            font-size: 0.55rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 0.5rem;
            vertical-align: middle;
        }
        .env-dev { background: rgba(250, 204, 21, 0.12); color: #facc15; }
        .env-prod { background: rgba(52, 211, 153, 0.12); color: #34d399; }
        .dashboard {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }
        .section-title {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #334155;
            margin-bottom: 0.75rem;
            padding-left: 0.25rem;
        }
        .cards {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }
        .card {
            background: #0c0c14;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 1.25rem 1.35rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.2s ease;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        .card::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .card:hover {
            background: #10101a;
            border-color: rgba(255, 255, 255, 0.08);
            transform: translateX(2px);
        }
        .card:hover::after { opacity: 1; }
        .card-app::after { background: #34d399; }
        .card-infra::after { background: #60a5fa; }
        .card-monitor::after { background: #a78bfa; }
        .icon {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .icon-app { background: rgba(52, 211, 153, 0.08); color: #34d399; }
        .icon-infra { background: rgba(96, 165, 250, 0.08); color: #60a5fa; }
        .icon-monitor { background: rgba(167, 139, 250, 0.08); color: #a78bfa; }
        .card-info { flex: 1; min-width: 0; }
        .card-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #e2e8f0;
        }
        .card-desc {
            color: #475569;
            font-size: 0.75rem;
            margin-top: 0.1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stats {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto 2.5rem;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }
        .stat-card {
            background: #0c0c14;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 1rem 1.25rem;
        }
        .stat-label {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #475569;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #e2e8f0;
            margin: 0.35rem 0 0.5rem;
            font-variant-numeric: tabular-nums;
        }
        .stat-bar {
            background: rgba(255, 255, 255, 0.05);
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
        }
        .stat-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        .stat-bar-fill.low  { background: #34d399; }
        .stat-bar-fill.mid  { background: #facc15; }
        .stat-bar-fill.high { background: #f87171; }
        .stat-sub {
            font-size: 0.7rem;
            color: #475569;
            margin-top: 0.5rem;
            font-variant-numeric: tabular-nums;
        }

        .operations {
            width: 100%;
            max-width: 1100px;
            margin: 3rem auto 0;
        }
        .operations .section-title { margin-bottom: 0.75rem; }
        .ops-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.6rem;
        }
        .ops-btn {
            background: #0c0c14;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            padding: 1.25rem 1.35rem;
            color: #e2e8f0;
            text-align: left;
            cursor: pointer;
            font: inherit;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .ops-btn:hover {
            background: #10101a;
            border-color: rgba(255, 255, 255, 0.08);
            transform: translateX(2px);
        }
        .ops-btn .icon { background: rgba(248, 113, 113, 0.08); color: #f87171; }
        .ops-form { margin: 0; }
        .ops-btn-name {
            font-weight: 600;
            font-size: 0.95rem;
        }
        .ops-btn-desc {
            color: #475569;
            font-size: 0.75rem;
            margin-top: 0.1rem;
        }

        .restore-danger {
            background: rgba(248, 113, 113, 0.03);
            border: 1px solid rgba(248, 113, 113, 0.12);
            border-radius: 16px;
            padding: 1.5rem;
        }
        .snapshot-row {
            display: flex;
            align-items: baseline;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .snapshot-row label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #f87171;
            flex-shrink: 0;
        }
        .snapshot-row input {
            flex: 1;
            background: #0c0c14;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 0.6rem 0.9rem;
            color: #e2e8f0;
            font: inherit;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.85rem;
        }
        .snapshot-row input:focus {
            outline: none;
            border-color: rgba(248, 113, 113, 0.4);
        }
        .restore-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.6rem;
        }
        .ops-btn-danger { border-color: rgba(248, 113, 113, 0.18); }
        .ops-btn-danger:hover { border-color: rgba(248, 113, 113, 0.35); }

        @media (max-width: 700px) {
            .dashboard { grid-template-columns: 1fr; gap: 1rem; }
            .ops-grid { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
            .restore-grid { grid-template-columns: 1fr; }
            .snapshot-row { flex-direction: column; align-items: stretch; gap: 0.5rem; }
        }
    </style>
</head>
<body>
    <?php
    $scheme = getenv('ADMIN_SCHEME') ?: 'https';
    $domain = getenv('ADMIN_DOMAIN') ?: 'localhost';
    $port = getenv('ADMIN_PORT') ? ':' . getenv('ADMIN_PORT') : '';
    $is_dev = $scheme === 'http';

    $sections = [
        'Applications' => [
            ['name' => 'Main Site', 'sub' => '',          'desc' => 'Landing page',         'type' => 'app', 'icon' => '&#9670;'],
            ['name' => 'DogeClaw',  'sub' => 'dogeclaw.', 'desc' => 'AI agent',             'type' => 'app', 'icon' => '&#128054;'],
            ['name' => 'TeleBot',   'sub' => 'bot.',      'desc' => 'Anonymous chat bot',   'type' => 'app', 'icon' => '&#128172;'],
        ],
        'Infrastructure' => [
            ['name' => 'Traefik',   'sub' => 'traefik.',   'desc' => 'Reverse proxy',     'type' => 'infra', 'icon' => '&#9881;'],
            ['name' => 'Portainer', 'sub' => 'portainer.', 'desc' => 'Docker management', 'type' => 'infra', 'icon' => '&#9638;'],
            ['name' => 'DbGate',    'sub' => 'db.',        'desc' => 'Database client',   'type' => 'infra', 'icon' => '&#128452;'],
        ],
        'Monitoring' => [
            ['name' => 'Uptime Kuma', 'sub' => 'status.', 'desc' => 'Uptime monitoring', 'type' => 'monitor', 'icon' => '&#9829;'],
            ['name' => 'GlitchTip', 'sub' => 'errors.', 'desc' => 'Error tracking', 'type' => 'monitor', 'icon' => '&#9888;'],
        ],
    ];
    ?>

    <div class="header">
        <h1>
            NullSpace
            <span class="env-badge <?= $is_dev ? 'env-dev' : 'env-prod' ?>">
                <?= $is_dev ? 'dev' : 'prod' ?>
            </span>
        </h1>
        <p>Service dashboard</p>
    </div>

    <div class="stats">
        <?php
        $stat_views = [
            ['key' => 'disk',   'label' => 'Disk'],
            ['key' => 'memory', 'label' => 'Memory'],
            ['key' => 'cpu',    'label' => 'CPU'],
        ];
        foreach ($stat_views as $sv):
            $s = $stats[$sv['key']];
            $pct = $s['pct'] ?? null;
            $tone = pct_tone($pct);
            if ($sv['key'] === 'cpu') {
                $sub = $s && isset($s['cores']) ? $s['cores'] . ' cores' : '';
            } elseif ($s) {
                $sub = format_bytes($s['used']) . ' / ' . format_bytes($s['total']);
            } else {
                $sub = '';
            }
        ?>
        <div class="stat-card">
            <div class="stat-label"><?= htmlspecialchars($sv['label']) ?></div>
            <div class="stat-value"><?= $pct === null ? 'N/A' : $pct . '%' ?></div>
            <div class="stat-bar">
                <div class="stat-bar-fill <?= $tone ?>" style="width: <?= $pct === null ? 0 : $pct ?>%"></div>
            </div>
            <?php if ($sub): ?>
            <div class="stat-sub"><?= htmlspecialchars($sub) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="dashboard">
        <?php foreach ($sections as $title => $services): ?>
        <div class="section">
            <div class="section-title"><?= $title ?></div>
            <div class="cards">
                <?php foreach ($services as $s):
                    $url = "{$scheme}://{$s['sub']}{$domain}{$port}";
                ?>
                <a href="<?= $url ?>" target="_blank" class="card card-<?= $s['type'] ?>">
                    <div class="icon icon-<?= $s['type'] ?>"><?= $s['icon'] ?></div>
                    <div class="card-info">
                        <div class="card-name"><?= $s['name'] ?></div>
                        <div class="card-desc"><?= $s['desc'] ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php
    $operations = [
        ['action' => 'deploy',          'name' => 'Deploy',          'desc' => 'git pull + docker compose pull + up -d', 'icon' => '&#9889;'],
        ['action' => 'backup-postgres', 'name' => 'Backup Postgres', 'desc' => 'pg_dumpall to /var/backups/nullspace',    'icon' => '&#128190;'],
        ['action' => 'renew-certs',     'name' => 'Renew Certs',     'desc' => 'Restart traefik to retry Let\'s Encrypt', 'icon' => '&#128274;'],
        ['action' => 'cleanup',         'name' => 'Cleanup',         'desc' => 'Prune unused images and build cache',     'icon' => '&#129529;'],
    ];
    ?>
    <div class="operations">
        <div class="section-title">VPS Operations</div>
        <div class="ops-grid">
            <?php foreach ($operations as $op): ?>
            <form method="POST" action="/run.php" target="_blank" class="ops-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="<?= htmlspecialchars($op['action']) ?>">
                <button type="submit" class="ops-btn">
                    <div class="icon"><?= $op['icon'] ?></div>
                    <div class="card-info">
                        <div class="ops-btn-name"><?= htmlspecialchars($op['name']) ?></div>
                        <div class="ops-btn-desc"><?= htmlspecialchars($op['desc']) ?></div>
                    </div>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    $restore_readonly = [
        ['action' => 'restore-list',  'name' => 'List Snapshots', 'desc' => 'restic snapshots — browse the off-site repo',  'icon' => '&#128203;'],
        ['action' => 'restore-check', 'name' => 'Check Repo',     'desc' => 'restic check — verify repo integrity',          'icon' => '&#9989;'],
    ];
    $restore_destructive = [
        ['action' => 'restore-env',  'name' => 'Restore .env',  'desc' => 'replace /opt/NullSpace/.env from snapshot',                'icon' => '&#128272;'],
        ['action' => 'restore-pg',   'name' => 'Restore PG',    'desc' => 'replay latest pg dump from snapshot',                       'icon' => '&#128190;'],
        ['action' => 'restore-full', 'name' => 'Full Restore',  'desc' => 'STOP stack, restore .env + volumes + pg, bring back up',    'icon' => '&#9888;'],
    ];
    ?>
    <div class="operations">
        <div class="section-title">Backup Restore — read-only</div>
        <div class="ops-grid">
            <?php foreach ($restore_readonly as $op): ?>
            <form method="POST" action="/run.php" target="_blank" class="ops-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="<?= htmlspecialchars($op['action']) ?>">
                <button type="submit" class="ops-btn">
                    <div class="icon"><?= $op['icon'] ?></div>
                    <div class="card-info">
                        <div class="ops-btn-name"><?= htmlspecialchars($op['name']) ?></div>
                        <div class="ops-btn-desc"><?= htmlspecialchars($op['desc']) ?></div>
                    </div>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="operations">
        <div class="section-title">Backup Restore — destructive</div>
        <form method="POST" action="/run.php" target="_blank" class="restore-danger ops-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="snapshot-row">
                <label for="snapshot-id">Snapshot</label>
                <input type="text" id="snapshot-id" name="snapshot"
                       placeholder="latest or hex id (e.g. ab12cd34)"
                       pattern="^(latest|[a-fA-F0-9]{4,64})$"
                       title="Either &quot;latest&quot; or a hex snapshot id"
                       required>
            </div>
            <div class="restore-grid">
                <?php foreach ($restore_destructive as $op):
                    $confirm = $op['name'] . ': destructive — modifies host state on the VPS. Continue?';
                ?>
                <button type="submit" name="action" value="<?= htmlspecialchars($op['action']) ?>"
                        class="ops-btn ops-btn-danger"
                        onclick="return confirm('<?= htmlspecialchars($confirm, ENT_QUOTES) ?>')">
                    <div class="icon"><?= $op['icon'] ?></div>
                    <div class="card-info">
                        <div class="ops-btn-name"><?= htmlspecialchars($op['name']) ?></div>
                        <div class="ops-btn-desc"><?= htmlspecialchars($op['desc']) ?></div>
                    </div>
                </button>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</body>
</html>
