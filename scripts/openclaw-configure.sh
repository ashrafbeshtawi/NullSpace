#!/bin/sh
set -e

# Run onboarding if not done yet (checks for wizard marker)
if ! openclaw config get wizard.lastRunAt 2>/dev/null | grep -q .; then
  echo "[openclaw-configure] Running first-time onboarding..."
  openclaw onboard \
    --non-interactive --accept-risk \
    --no-install-daemon \
    --skip-channels --skip-health --skip-search --skip-skills --skip-ui \
    --flow manual \
    --auth-choice openrouter-api-key \
    --openrouter-api-key "$OPENROUTER_API_KEY" \
    --gateway-auth token \
    --gateway-token-ref-env OPENCLAW_GATEWAY_TOKEN \
    --gateway-bind lan
  echo "[openclaw-configure] Onboarding complete."
fi

# Build the batch-json payload from env vars
BATCH=$(node -e "
  const origins = (process.env.OPENCLAW_ALLOWED_ORIGINS || '').split(',').filter(Boolean);
  const proxies = (process.env.OPENCLAW_TRUSTED_PROXIES || '').split(',').filter(Boolean);
  console.log(JSON.stringify([
    {path: 'gateway.controlUi.allowedOrigins', value: origins},
    {path: 'gateway.trustedProxies', value: proxies},
    {path: 'agents.defaults.sandbox.mode', value: 'off'},
  ]));
")

echo "[openclaw-configure] Applying config overlay..."
openclaw config set --batch-json "$BATCH"
echo "[openclaw-configure] Done."
