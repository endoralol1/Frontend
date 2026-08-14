# Inbox polls & notifications

Header bell (top-right) for **polls** and **notifications**.

## Behavior

- Bell icon appears only when there is at least one **active** inbox item; otherwise it stays hidden.
- Dropdown lists active (and recently closed) items.
- Unread badge when the viewer has not opened new active items yet.
- Guests are keyed by session cookie `ns_inbox` (expires when the browser closes).
- Signed-in users use `u:{userId}` so votes/reads persist across sessions.
- Like / dislike reactions (optional per item).
- Advanced poll settings: guests, multi-select, show-results mode, auth requirements, pin, end time.

## Admin

- Page: `/admin/inbox` (staff)
- APIs: `/api/admin/inbox` (GET/POST), `/api/admin/inbox/{id}` (POST update / DELETE)

## Public APIs

- `GET /api/inbox`
- `POST /api/inbox/{id}/vote` `{ "optionIds": ["..."] }`
- `POST /api/inbox/{id}/react` `{ "reaction": "like"|"dislike"|null }`
- `POST /api/inbox/{id}/read`
- `POST /api/inbox/read-all`

## Deploy

Copy patch files to the VPS (or run `deploy.py` on the server with sources under `/tmp/inbox-polls`):

```bash
# from this folder, sync newsite/ tree then:
python3 deploy.py
```

Live site path: `/var/www/chillflix-newsite`.
