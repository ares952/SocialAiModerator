---
name: socialai-maintenance
description: Turn the Social AI Moderator maintenance page on or off, check its state and verify it from outside and through the allowlist. Use for requests about maintenance mode, outage pages or blocking access during manual work.
---

# Social AI Moderator maintenance

Respond in the user's language. Read **Maintenance** in
[the release lifecycle](../../../docs/release-lifecycle.md).

- `bin/maintenance status` is read-only and always allowed.
- `bin/maintenance on` and `off` change what every visitor and Meta see; run them only when the
  user asks or within an approved `socialai-release-update` / `socialai-update-images` plan.
  Maintenance also answers Meta webhooks with 503 (Meta redelivers later), so keep it short and
  remind the user when it stays on.

## Verify

After switching, check (with the values from `.env` and `config/secrets/app.env`, without
printing secrets):

- public URL (`APP_URL`): HTTP 503 with the maintenance page and `Retry-After` when on, the
  application when off;
- `docker compose exec -T web wget -qO- http://127.0.0.1/` returns the application in both
  states (allowlisted);
- `/health/live` stays 200 in both states.

To let an administrator verify a release during maintenance, add their exact client address
to `config/nginx/maintenance-allowlist.geo` (`<address> 1;`) and run
`docker compose exec web nginx -s reload`. Remove the entry afterwards. Never add whole LAN ranges.
