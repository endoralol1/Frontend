#!/usr/bin/env python3
"""Make Watch Party chat wider/full-bleed on phone; roomier on desktop."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
watch = root / "app/Views/pages/watch.php"
css = root / "public/assets/css/continue-party.css"
main = root / "app/Views/layouts/main.php"
routes = root / "app/routes.php"
player = root / "app/Views/layouts/player.php"

UI = "20260803-ui153"

# --- Move chat outside #movie-player so it can go full-bleed ---
w = watch.read_text()
chat_block = """                    <div id="cf-party-chat" class="cf-party-chat" hidden>
                        <div class="cf-party-chat-head">
                            <div class="cf-party-chat-title">
                                <span class="cf-party-chat-dot" aria-hidden="true"></span>
                                <strong>Party chat</strong>
                                <em class="cf-party-chat-code"></em>
                            </div>
                            <div class="cf-party-chat-host" hidden>
                                <button type="button" class="cf-party-chat-lock" id="cf-party-chat-lock" aria-pressed="false">Mute guests</button>
                            </div>
                        </div>
                        <div class="cf-party-chat-log" id="cf-party-chat-log" aria-live="polite"></div>
                        <p class="cf-party-chat-note" id="cf-party-chat-note" hidden></p>
                        <div class="cf-party-chat-login" id="cf-party-chat-login" hidden>
                            <p>Log in to join the party chat.</p>
                            <button type="button" class="cf-party-chat-login-btn" data-browse-auth="login">Log in</button>
                        </div>
                        <form class="cf-party-chat-form" id="cf-party-chat-form" autocomplete="off" hidden>
                            <div class="cf-party-chat-user" id="cf-party-chat-user"></div>
                            <input type="text" id="cf-party-chat-input" class="cf-party-chat-input" maxlength="200" placeholder="Say something…" aria-label="Chat message">
                            <button type="submit" class="cf-party-chat-send" id="cf-party-chat-send">Send</button>
                        </form>
                    </div>
"""

# Remove from inside movie-player if still there
if chat_block in w:
    w = w.replace(chat_block, "", 1)
    print("removed chat from inside movie-player")
elif 'id="cf-party-chat"' in w:
    # already moved or slightly different — leave structure, still widen CSS
    print("chat markup present; structure tweak skipped")
else:
    raise SystemExit("chat block missing")

# Insert after #movie-player closes (before seasons/episodes or next sibling in aside.main)
anchor = '                </div>\n\n                <?php if ($type === \'tv\'): ?>'
alt_anchor = '                </div>\n\n                <div class="section'
moved = (
    '                </div>\n\n'
    + chat_block.replace("                    ", "                ", 1).replace(
        "\n                        ", "\n                    "
    ).replace(
        "\n                            ", "\n                        "
    )
)

# Simpler: place right after the movie-player closing div by finding unique marker
marker = '                    <div id="movie-managers">'
# If chat was removed, movie-player still ends after managers. Find closing of movie-player.
# Pattern: end of movie-managers section closes movie-player.
close_movie = None
# Prefer inserting immediately after </div> that closes #movie-player.
# Look for movie-managers block end then parent close.
idx = w.find('id="movie-managers"')
if idx < 0:
    raise SystemExit("movie-managers not found")
# Find the closing of #movie-player: after managers there's typically `                </div>\n`
# Use a more reliable approach: insert before the next major sibling after movie-player.
# movie-player opens at id="movie-player"
start = w.find('id="movie-player"')
if start < 0:
    raise SystemExit("movie-player not found")
# walk from movie-player open tag to find matching close — rough via depth from that div
div_open = w.rfind("<div", 0, start)
# find start of the movie-player element
el_start = w.rfind("<div", 0, start + 1)
depth = 0
i = el_start
end = None
while i < len(w):
    nxt_open = w.find("<div", i)
    nxt_close = w.find("</div>", i)
    if nxt_close < 0:
        break
    if nxt_open != -1 and nxt_open < nxt_close:
        depth += 1
        i = nxt_open + 4
    else:
        depth -= 1
        i = nxt_close + 6
        if depth == 0:
            end = i
            break
if not end:
    raise SystemExit("could not find movie-player close")

if 'id="cf-party-chat"' not in w:
    insert = "\n\n" + chat_block.rstrip() + "\n"
    w = w[:end] + insert + w[end:]
    watch.write_text(w)
    print("inserted chat after movie-player")
else:
    # ensure it's outside: if still between movie-player open and close, move it
    chat_start = w.find('id="cf-party-chat"')
    chat_el = w.rfind("<div", 0, chat_start + 1)
    if el_start < chat_el < end:
        # extract chat element
        cdepth = 0
        ci = chat_el
        cend = None
        while ci < len(w):
            no = w.find("<div", ci)
            nc = w.find("</div>", ci)
            if nc < 0:
                break
            if no != -1 and no < nc:
                cdepth += 1
                ci = no + 4
            else:
                cdepth -= 1
                ci = nc + 6
                if cdepth == 0:
                    cend = ci
                    break
        block = w[chat_el:cend]
        # strip trailing whitespace/newlines carefully
        w2 = w[:chat_el] + w[cend:]
        # recompute movie-player end on w2
        start2 = w2.find('id="movie-player"')
        el_start2 = w2.rfind("<div", 0, start2 + 1)
        depth = 0
        i = el_start2
        end2 = None
        while i < len(w2):
            nxt_open = w2.find("<div", i)
            nxt_close = w2.find("</div>", i)
            if nxt_close < 0:
                break
            if nxt_open != -1 and nxt_open < nxt_close:
                depth += 1
                i = nxt_open + 4
            else:
                depth -= 1
                i = nxt_close + 6
                if depth == 0:
                    end2 = i
                    break
        w = w2[:end2] + "\n\n" + block + "\n" + w2[end2:]
        watch.write_text(w)
        print("moved chat outside movie-player")
    else:
        print("chat already outside movie-player")

# --- CSS widen ---
c = css.read_text()
old = """/* ——— Watch Party chat (below video) ——— */
.cf-party-chat[hidden] { display: none !important; }
.cf-party-chat {
  margin: 0.65rem 0 0.15rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(255,255,255,.1);
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), transparent 40%),
    rgba(12, 14, 20, .88);
  overflow: hidden;
}"""

new = """/* ——— Watch Party chat (below video) ——— */
.cf-party-chat[hidden] { display: none !important; }
.cf-party-chat {
  width: 100%;
  max-width: none;
  box-sizing: border-box;
  margin: 0.75rem 0 0.35rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(255,255,255,.1);
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), transparent 40%),
    rgba(12, 14, 20, .88);
  overflow: hidden;
}
/* Phone: edge-to-edge under the player (cancel .container 15px gutters) */
@media (max-width: 767.98px) {
  .cf-party-chat {
    width: calc(100% + 30px);
    max-width: calc(100% + 30px);
    margin-left: -15px;
    margin-right: -15px;
    margin-top: 0.55rem;
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }
  .cf-party-chat-log {
    height: min(48vh, 20rem);
    padding: .7rem .85rem;
  }
  .cf-party-chat-head {
    padding: .65rem .85rem;
  }
  .cf-party-chat-form,
  .cf-party-chat-login {
    padding-left: .85rem;
    padding-right: .85rem;
  }
  .cf-party-chat-msg-text { font-size: .92rem; }
}
@media (min-width: 768px) {
  .cf-party-chat-log {
    height: min(42vh, 18rem);
  }
}"""

if old not in c:
    if "width: calc(100% + 30px)" in c:
        print("wider css exists")
    else:
        raise SystemExit("chat css block not found")
else:
    c = c.replace(old, new, 1)
    # bump default log height if still old (desktop fallback already in media)
    c = c.replace(
        """.cf-party-chat-log {
  height: min(38vh, 14.5rem);
  overflow: auto;
  padding: .55rem .65rem;""",
        """.cf-party-chat-log {
  height: min(40vh, 16.5rem);
  overflow: auto;
  padding: .6rem .75rem;""",
        1,
    )
    css.write_text(c)
    print("wider chat css")

# bump assets
for p in (main, routes, player):
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", f"?v={UI}", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

print("OK", UI)
