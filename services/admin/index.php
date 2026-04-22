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

        @media (max-width: 700px) {
            .dashboard { grid-template-columns: 1fr; gap: 1rem; }
        }
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
            ['name' => 'DogeClaw', 'sub' => 'dogeclaw.', 'desc' => 'AI agent', 'type' => 'app', 'icon' => '&#128054;'],
        ],
        'Infrastructure' => [
            ['name' => 'Traefik', 'sub' => 'traefik.', 'desc' => 'Reverse proxy', 'type' => 'infra', 'icon' => '&#9881;'],
            ['name' => 'Portainer', 'sub' => 'portainer.', 'desc' => 'Docker management', 'type' => 'infra', 'icon' => '&#9638;'],
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
</body>
</html>
