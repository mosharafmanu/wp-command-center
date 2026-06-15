# Deployment (production: mosharafmanu.com)

**Model: PULL-BASED (server-side cron).** As of 2026-06-15 the host (Hostinger)
blocks inbound SSH from GitHub-hosted runner IPs (`ssh: connect to host
72.62.68.183 port 65002: Connection timed out`), so push-based CI deploy can't
reach the server. Instead the **server pulls from GitHub** (outbound is allowed).

## How a deploy happens
1. `git push origin main`.
2. A server cron (every minute) runs `~/wpcc-deploy.sh`, which:
   `git fetch` → if `origin/main` advanced: `git reset --hard origin/main` →
   reactivate plugin if it was active → `wp cache flush`. Idempotent + `flock`-guarded.
3. The commit is live within ~1 minute. Activity is logged to `~/wpcc-deploy.log`.

## Server bits
- Script: `/home/u916998506/wpcc-deploy.sh`
- Log: `/home/u916998506/wpcc-deploy.log`
- Cron (hPanel → Advanced → Cron Jobs): `* * * * *`
  `/bin/bash /home/u916998506/wpcc-deploy.sh >/dev/null 2>&1`

## Manual deploy / fallback (from an allowed IP)
```
ssh -p 65002 u916998506@72.62.68.183
bash ~/wpcc-deploy.sh            # or run the fetch/reset/flush by hand
```

## GitHub Actions
`.github/workflows/deploy.yml` is a **green no-op notice** — it no longer attempts
SSH (the host blocks the runner). Do not rely on it to deploy.

## If you want push-based CI deploy back
Check hPanel for an SSH IP-allowlist / firewall / "block cloud IPs" setting, or ask
Hostinger to whitelist GitHub Actions IP ranges — otherwise keep the pull model
(recommended for shared hosting).
