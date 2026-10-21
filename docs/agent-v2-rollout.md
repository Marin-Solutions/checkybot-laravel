# Agent v2 rollout guide

Operator steps to roll the Checkybot server agent v2 out to a fleet: preflight, canary, fleet rollout, token rotation, inventory and rollback.

The agent sends one report every interval to `POST /api/v2/agent-reports` (`routes/agent.php`). A report carries a `prerequisites` list. Each entry has a `kind` (`nginx_access_log`, `nginx_error_log`, `php_fpm_status`, `php_fpm_log`, `mysql_log`) and a `status` (`readable`, `missing`, `permission_denied`, `disabled`, `not_configured`). Fix every prerequisite that is not `readable` before you enable a server.

Set these shell variables once per host. The paths depend on how you installed the agent:

```bash
AGENT_USER=checkybot-agent              # system user the agent runs as
AGENT_STATE=/var/lib/checkybot-agent    # agent state directory (token, cursors)
CHECKYBOT_URL=https://checkybot.example.com
```

## 1. Preflight

### Private state storage

The agent state holds the report token. Only the agent user may read it (mode `0600`, directory `0700`).

```bash
sudo install -d -m 0700 -o "$AGENT_USER" -g "$AGENT_USER" "$AGENT_STATE"
sudo chmod 0600 "$AGENT_STATE"/*
stat -c '%a %U %n' "$AGENT_STATE" "$AGENT_STATE"/*   # expect 700 and 600, owner $AGENT_USER
```

### Nginx access log

The 5xx and upstream-timeout window needs an enabled `access_log`. An `access_log off;` reports `disabled`.

```bash
sudo nginx -T 2>/dev/null | grep -nE '^\s*(access_log|error_log)\b'
# Enable it in the server block, for example: access_log /var/log/nginx/access.log;
sudo nginx -t && sudo systemctl reload nginx
```

### Log read access (least privilege)

On Debian and Ubuntu, nginx, PHP-FPM and MySQL logs belong to the `adm` group. Add the agent user to `adm` instead of running the agent as root.

```bash
sudo usermod -aG adm "$AGENT_USER"
sudo systemctl restart checkybot-agent        # group changes need a new process
sudo -u "$AGENT_USER" test -r /var/log/nginx/access.log && echo readable || echo permission_denied
```

If a log must stay outside `adm`, allow exactly one read check with sudo, never a shell:

```bash
echo "$AGENT_USER ALL=(root) NOPASSWD: /usr/bin/test -r /var/log/mysql/error.log" | sudo tee /etc/sudoers.d/checkybot-agent
sudo chmod 0440 /etc/sudoers.d/checkybot-agent && sudo visudo -c
sudo -u "$AGENT_USER" sudo -n test -r /var/log/mysql/error.log && echo readable || echo permission_denied
```

### PHP-FPM status and log

`php_fpm_status` needs `pm.status_path` in each pool and a local-only location for it.

```bash
grep -rn '^pm.status_path' /etc/php/*/fpm/pool.d/      # expect pm.status_path = /fpm-status
sudo systemctl reload php8.3-fpm                        # use your installed php-fpm version
curl -fsS http://127.0.0.1/fpm-status | head -5        # must answer from localhost only
sudo -u "$AGENT_USER" test -r /var/log/php8.3-fpm.log && echo readable
```

### MySQL error log (optional)

`mysql_log` is optional. Without it the report says `not_configured`, which is fine.

```bash
mysql -NBe "SHOW VARIABLES LIKE 'log_error'"
sudo -u "$AGENT_USER" test -r /var/log/mysql/error.log && echo readable || echo missing
```

## 2. Network link cap

Network saturation is measured against the server's link cap. New servers default to 1 Gbit/s (`RegisteredServer::DEFAULT_LINK_CAP_BPS`, 1,000,000,000 bits per second). Set the real uplink for faster or slower servers with `OverrideServerLinkCap`:

```bash
php artisan tinker --execute='
app(\MarinSolutions\CheckybotLaravel\Domain\Agent\Actions\OverrideServerLinkCap::class)
    ->execute("<project-id>", "<server-uuid>", 10_000_000_000);'
```

The value must be a positive integer, and the server must be registered in that project.

## 3. Report tokens

Each host gets its own project token with only the `agent:report` ability. The plain token is shown once; store it in the private agent state (`0600`).

```bash
php artisan tinker --execute='
$issued = \MarinSolutions\CheckybotLaravel\Models\ProjectApiToken::issue("<project-id>", "agent <hostname>", ["agent:report"]);
echo $issued->plainTextToken(), PHP_EOL;'
```

Rotate a token: `rotate()` issues a new token and ends the old one. Write the new value to the host, restart the agent, then check that reports still arrive.

```bash
php artisan tinker --execute='
$token = \MarinSolutions\CheckybotLaravel\Models\ProjectApiToken::query()->where("name", "agent <hostname>")->whereNull("revoked_at")->firstOrFail();
echo $token->rotate(["agent:report"])->plainTextToken(), PHP_EOL;'
```

Revoke a leaked or retired token at once:

```bash
php artisan tinker --execute='
\MarinSolutions\CheckybotLaravel\Models\ProjectApiToken::query()->where("name", "agent <hostname>")->whereNull("revoked_at")->firstOrFail()->revoke();'
```

## 4. Canary rollout

Roll out in stages: one canary host, then about 10 % of the fleet, then the rest. Enable the next stage only when every server of the current stage reports for at least two intervals and shows no `permission_denied`, `missing` or `disabled` prerequisite.

```bash
# On the canary host: install the new agent version, then start it.
sudo systemctl restart checkybot-agent && sudo journalctl -u checkybot-agent -n 50 --no-pager
# Prove the token and endpoint before you wait for the first interval:
curl -sS -o /dev/null -w '%{http_code}\n' -X POST "$CHECKYBOT_URL/api/v2/agent-reports" \
  -H "Authorization: Bearer $(sudo cat "$AGENT_STATE/token")" -H 'Accept: application/json' -d '{}'
# 422 means token and route are fine (empty body rejected); 401 or 403 means a token problem.
```

Verify the canary on the Checkybot side:

```bash
php artisan tinker --execute='
\Illuminate\Support\Facades\DB::table("agent_reports")
    ->where("agent_server_id", \Illuminate\Support\Facades\DB::table("agent_servers")->where("server_uuid", "<server-uuid>")->value("id"))
    ->latest("observed_at")->limit(3)
    ->get(["observed_at", "agent_version", "payload->prerequisites as prerequisites"])
    ->each(fn ($row) => print(json_encode($row).PHP_EOL));'
```

## 5. Fleet inventory

List the `agent_version` each enabled server last reported, so you see which hosts still run the old agent:

```bash
php artisan tinker --execute='
\Illuminate\Support\Facades\DB::table("agent_servers as s")
    ->where("s.enabled", true)
    ->selectRaw("s.server_uuid, (select r.agent_version from agent_reports r where r.agent_server_id = s.id order by r.observed_at desc limit 1) as agent_version")
    ->orderBy("agent_version")
    ->get()->each(fn ($row) => print($row->server_uuid." ".($row->agent_version ?? "never reported").PHP_EOL));'
```

## 6. Rollback

Roll back one stage at a time, newest stage first.

```bash
# 1. Stop alerts from the host: disable the server record.
php artisan tinker --execute='
\Illuminate\Support\Facades\DB::table("agent_servers")->where("server_uuid", "<server-uuid>")->update(["enabled" => false]);'
# 2. On the host: reinstall the previous agent version and restart it.
sudo systemctl restart checkybot-agent
# 3. When the old version reports again, enable the server record.
php artisan tinker --execute='
\Illuminate\Support\Facades\DB::table("agent_servers")->where("server_uuid", "<server-uuid>")->update(["enabled" => true]);'
```

If the token leaked or the host is retired, revoke its token (section 3) and keep the server disabled.

## 7. Prerequisite status remediation

| Status | Meaning | Fix |
| --- | --- | --- |
| `readable` | The agent can read the source. | Nothing to do. |
| `missing` | The configured path does not exist. | Check the path with `nginx -T`, the FPM pool config or `SHOW VARIABLES LIKE 'log_error'`, then point the agent at the real file. |
| `permission_denied` | The file exists, but the agent user cannot read it. | Add the agent user to `adm` or add the single `sudo -n test -r` rule (section 1), then restart the agent. |
| `disabled` | The source is switched off, for example `access_log off;` or no `pm.status_path`. | Enable it (section 1) and reload nginx or php-fpm. |
| `not_configured` | The agent has no path for this source. | Optional sources such as `mysql_log` may stay so. For `php_fpm_status` and `nginx_access_log`, configure the path, because the evaluator warns without them. |

Check one fix at once from the host:

```bash
for f in /var/log/nginx/access.log /var/log/nginx/error.log /var/log/php8.3-fpm.log /var/log/mysql/error.log; do
  if [ ! -e "$f" ]; then echo "missing $f"; elif sudo -u "$AGENT_USER" test -r "$f"; then echo "readable $f"; else echo "permission_denied $f"; fi
done
```
