# SG human unlock (vuflix + chillflix)

## Why GA dropped
The Singapore country gate blocks **all** `CF-IPCountry: SG` traffic without
`cf_human_ok=1`. That removed the bot farm (~97% of “users”) **and** any real
Singapore visitors (silent `403 Forbidden`). Non-SG users are not blocked by
this rule.

## Change
- Replace silent 403 with `/sg-gate.html` (“Continue” → `/sg-unlock`)
- Keep hard 403 for known bad bot UAs
- Do **not** auto-set the unlock cookie (bots would unlock themselves)

## Apply
```bash
bash chillflix-patches/sg-human-unlock/apply.sh
```
