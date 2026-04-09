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
            background: #0a0a0f;
            color: #e2e8f0;
            min-height: 100vh;
        }
        .header {
            padding: 2.5rem 2rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #f8fafc;
            letter-spacing: -0.025em;
        }
        .header p {
            color: #64748b;
            margin-top: 0.25rem;
            font-size: 0.875rem;
        }
        .content { padding: 2rem; }
        .section-title {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #475569;
            margin-bottom: 0.75rem;
            padding-left: 0.25rem;
        }
        .section { margin-bottom: 2rem; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 0.75rem;
        }
        .card {
            background: #111118;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .card:hover {
            background: #16161f;
            border-color: rgba(255,255,255,0.12);
            transform: translateY(-1px);
        }
        .icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .icon-app { background: rgba(52, 211, 153, 0.1); color: #34d399; }
        .icon-infra { background: rgba(96, 165, 250, 0.1); color: #60a5fa; }
        .icon-monitor { background: rgba(167, 139, 250, 0.1); color: #a78bfa; }
        .card-info { flex: 1; min-width: 0; }
        .card-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #f1f5f9;
        }
        .card-desc {
            color: #64748b;
            font-size: 0.8rem;
            margin-top: 0.15rem;
        }
        .card-url {
            color: #475569;
            font-size: 0.7rem;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
            margin-top: 0.25rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .arrow {
            color: #334155;
            font-size: 1.1rem;
            transition: color 0.2s;
        }
        .card:hover .arrow { color: #64748b; }
        .env-badge {
            display: inline-block;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 3px 8px;
            border-radius: 6px;
            margin-left: 0.75rem;
            vertical-align: middle;
        }
        .env-dev { background: rgba(250, 204, 21, 0.1); color: #facc15; }
        .env-prod { background: rgba(52, 211, 153, 0.1); color: #34d399; }
    </style>
</head>
<body>
    <?php
    $scheme = getenv('ADMIN_SCHEME') ?: 'https';
    $domain = getenv('ADMIN_DOMAIN') ?: 'beshtawi.online';
    $port = getenv('ADMIN_PORT') ? ':' . getenv('ADMIN_PORT') : '';
    $is_dev = $scheme === 'http';

    $sections = [
        'Applications' => [
            ['name' => 'Main Site', 'sub' => '', 'desc' => 'Landing page', 'type' => 'app', 'icon' => '&#9670;'],
        ],
        'Infrastructure' => [
            ['name' => 'Traefik', 'sub' => 'traefik.', 'desc' => 'Reverse proxy dashboard', 'type' => 'infra', 'icon' => '&#9881;'],
            ['name' => 'Portainer', 'sub' => 'portainer.', 'desc' => 'Docker container management', 'type' => 'infra', 'icon' => '&#9638;'],
        ],
        'Monitoring' => [
            ['name' => 'Uptime Kuma', 'sub' => 'status.', 'desc' => 'Uptime monitoring & status page', 'type' => 'monitor', 'icon' => '&#9829;'],
            ['name' => 'GlitchTip', 'sub' => 'errors.', 'desc' => 'Error tracking (Sentry-compatible)', 'type' => 'monitor', 'icon' => '&#9888;'],
            ['name' => 'Dozzle', 'sub' => 'logs.', 'desc' => 'Real-time container logs', 'type' => 'monitor', 'icon' => '&#9776;'],
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

    <div class="content">
        <?php foreach ($sections as $title => $services): ?>
        <div class="section">
            <div class="section-title"><?= $title ?></div>
            <div class="grid">
                <?php foreach ($services as $s):
                    $url = "{$scheme}://{$s['sub']}{$domain}{$port}";
                ?>
                <a href="<?= $url ?>" target="_blank" class="card">
                    <div class="icon icon-<?= $s['type'] ?>"><?= $s['icon'] ?></div>
                    <div class="card-info">
                        <div class="card-name"><?= $s['name'] ?></div>
                        <div class="card-desc"><?= $s['desc'] ?></div>
                        <div class="card-url"><?= $s['sub'] . $domain . $port ?></div>
                    </div>
                    <div class="arrow">&#8594;</div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
