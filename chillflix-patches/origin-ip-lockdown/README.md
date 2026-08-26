# Origin IP lockdown (VPS)

Stops scanners from linking the VPS IP to chillflix/vuflix via bare-IP TLS certs or HTTP redirects.

## Applied on VPS

1. **nginx `00-default-drop`** — default server on `:80`/`:443` uses a dummy `CN=localhost` cert and `return 444` (no chillflix redirect).
2. **UFW** — deny incoming by default; allow SSH `:22`; allow `:80`/`:443` only from [Cloudflare IP ranges](https://www.cloudflare.com/ips/).

## Safe for site/player

Visitor traffic stays Cloudflare (orange cloud) → origin. Playback `/api/cinepro/*` continues through CF.

## Re-apply / refresh CF ranges

```bash
sudo bash /root/scripts/origin-ip-lockdown.sh
```

Re-run when Cloudflare publishes new IP ranges.

## Notes

- Domains must stay **proxied** (orange cloud). Grey-cloud would block public access.
- Certbot `nginx` HTTP-01 still works while proxied (LE hits CF, CF hits origin from CF IPs).
- Do **not** enable Cloudflare WARP on this VPS.
