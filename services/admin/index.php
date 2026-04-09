<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NullSpace Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; padding: 2rem; }
        h1 { font-size: 1.5rem; margin-bottom: 2rem; color: #f8fafc; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 1.25rem; transition: border-color 0.2s; }
        .card:hover { border-color: #60a5fa; }
        .card a { color: #60a5fa; text-decoration: none; font-weight: 600; font-size: 1.1rem; }
        .card a:hover { text-decoration: underline; }
        .card p { color: #94a3b8; margin-top: 0.5rem; font-size: 0.875rem; }
        .badge { display: inline-block; font-size: 0.7rem; padding: 2px 8px; border-radius: 4px; margin-left: 0.5rem; font-weight: 400; }
        .infra { background: #1e3a5f; color: #7dd3fc; }
        .app { background: #1a3329; color: #6ee7b7; }
        .monitor { background: #3b1f4a; color: #c4b5fd; }
    </style>
</head>
<body>
    <h1>NullSpace Admin Panel</h1>
    <div class="grid">
        <?php
        $scheme = getenv('ADMIN_SCHEME') ?: 'https';
        $domain = getenv('ADMIN_DOMAIN') ?: 'beshtawi.online';
        $port = getenv('ADMIN_PORT') ? ':' . getenv('ADMIN_PORT') : '';

        $services = [
            ['name' => 'Main Site', 'sub' => '', 'desc' => 'Landing page', 'type' => 'app'],
            ['name' => 'Traefik Dashboard', 'sub' => 'traefik.', 'desc' => 'Reverse proxy dashboard', 'type' => 'infra'],
            ['name' => 'Portainer', 'sub' => 'portainer.', 'desc' => 'Docker container management', 'type' => 'infra'],
            ['name' => 'Uptime Kuma', 'sub' => 'status.', 'desc' => 'Uptime monitoring & status page', 'type' => 'monitor'],
            ['name' => 'GlitchTip', 'sub' => 'errors.', 'desc' => 'Error tracking (Sentry-compatible)', 'type' => 'monitor'],
            ['name' => 'Dozzle', 'sub' => 'logs.', 'desc' => 'Real-time container logs', 'type' => 'monitor'],
        ];

        foreach ($services as $s) {
            $url = "{$scheme}://{$s['sub']}{$domain}{$port}";
            $badge = match($s['type']) {
                'infra' => '<span class="badge infra">infra</span>',
                'monitor' => '<span class="badge monitor">monitoring</span>',
                default => '<span class="badge app">app</span>',
            };
            echo "<div class='card'>";
            echo "<a href='{$url}' target='_blank'>{$s['name']}</a>{$badge}";
            echo "<p>{$s['desc']}</p>";
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>
