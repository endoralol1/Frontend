#!/usr/bin/env python3
"""Wire subtitle style prefs + pass-through into MediaPlayer.tsx."""
from pathlib import Path

MP = Path("/var/www/chillflix.lol/components/player/MediaPlayer.tsx")
text = MP.read_text()

if "subtitle-style-prefs" in text:
    print("MediaPlayer already patched")
else:
    # import
    old_import = 'import { CustomSubtitles } from "./CustomSubtitles"'
    new_import = (
        'import { CustomSubtitles } from "./CustomSubtitles"\n'
        'import {\n'
        '  readSubtitleStylePrefs,\n'
        '  writeSubtitleStylePrefs,\n'
        '  type SubtitleStylePrefs,\n'
        '} from "@/lib/subtitle-style-prefs"'
    )
    if old_import not in text:
        raise SystemExit("CustomSubtitles import not found")
    text = text.replace(old_import, new_import, 1)

    # state after other useState near top of component - find a stable hook anchor
    # Insert after first useState block is fragile; use CustomSubtitles call site for props
    # and add state near autoNextEnabled usage in component body.

    # Find: const { t } = useTranslations() inside component - add state after a known line
    anchor = "  const { t } = useTranslations()\n"
    if anchor not in text:
        # try alternate
        anchor = "  const { t } = useTranslations()\r\n"
    if "readSubtitleStylePrefs" not in text.split("CustomSubtitles")[0][-2000:]:
        # Insert state near start of component after translations
        # Search for unique occurrence inside MediaPlayer component
        idx = text.find("export function MediaPlayer")
        if idx < 0:
            idx = text.find("export const MediaPlayer")
        sub = text[idx:]
        t_idx = sub.find("const { t } = useTranslations()")
        if t_idx < 0:
            raise SystemExit("useTranslations not found in MediaPlayer")
        insert_at = idx + t_idx + len("const { t } = useTranslations()\n")
        # handle if next char after ) is not newline already consumed
        if text[insert_at - 1] != "\n":
            # find end of line
            nl = text.find("\n", idx + t_idx)
            insert_at = nl + 1
        state_block = """  const [subtitleStyle, setSubtitleStyle] = useState<SubtitleStylePrefs>(() =>
    readSubtitleStylePrefs()
  )

  const updateSubtitleStyle = useCallback((patch: Partial<SubtitleStylePrefs>) => {
    setSubtitleStyle((prev) => {
      const next = { ...prev, ...patch }
      writeSubtitleStylePrefs(next)
      return next
    })
  }, [])

"""
        text = text[:insert_at] + state_block + text[insert_at:]
        print("inserted subtitle style state")

    # CustomSubtitles props
    old_subs = """        {selectedSubtitle ? (
          <CustomSubtitles
            url={selectedSubtitle.src}
            videoRef={videoRef}
            onLoadError={handleSubtitleLoadError}
          />
        ) : null}"""
    new_subs = """        {selectedSubtitle ? (
          <CustomSubtitles
            url={selectedSubtitle.src}
            videoRef={videoRef}
            delaySec={subtitleStyle.delaySec}
            fontScale={subtitleStyle.fontScale}
            backgroundOpacity={subtitleStyle.backgroundOpacity}
            onLoadError={handleSubtitleLoadError}
          />
        ) : null}"""
    if old_subs not in text:
        raise SystemExit("CustomSubtitles JSX block not found")
    text = text.replace(old_subs, new_subs, 1)

    # Pass props to PlayerControls - find autoNextEnabled={autoNextEnabled} near PlayerControls
    # There may be multiple; replace the ones followed by onAutoNextChange in controls
    marker = """        autoNextEnabled={autoNextEnabled}
        onAutoNextChange={onAutoNextChange}"""
    replacement = """        autoNextEnabled={autoNextEnabled}
        onAutoNextChange={onAutoNextChange}
        subtitleDelaySec={subtitleStyle.delaySec}
        onSubtitleDelayChange={(delaySec) => updateSubtitleStyle({ delaySec })}
        subtitleFontScale={subtitleStyle.fontScale}
        onSubtitleFontScaleChange={(fontScale) => updateSubtitleStyle({ fontScale })}
        subtitleBackgroundOpacity={subtitleStyle.backgroundOpacity}
        onSubtitleBackgroundOpacityChange={(backgroundOpacity) =>
          updateSubtitleStyle({ backgroundOpacity })
        }"""
    count = text.count(marker)
    if count < 1:
        raise SystemExit("autoNextEnabled marker not found for PlayerControls")
    text = text.replace(marker, replacement)
    print(f"patched PlayerControls props ({count} sites)")

    # ensure useCallback is imported
    if "useCallback" not in text.split("\n")[0:40].__str__() and "useCallback" not in text[:1500]:
        text = text.replace(
            "import { useCallback, useEffect, useMemo, useRef, useState",
            "import { useCallback, useEffect, useMemo, useRef, useState",
            1,
        )
        # if already has useCallback fine; if not add it
        if "useCallback" not in text[:2000]:
            text = text.replace(
                "import {\n  useEffect,",
                "import {\n  useCallback,\n  useEffect,",
                1,
            )
            text = text.replace(
                "import { useEffect, useMemo, useRef, useState",
                "import { useCallback, useEffect, useMemo, useRef, useState",
                1,
            )
            text = text.replace(
                "import { useEffect, useRef, useState",
                "import { useCallback, useEffect, useRef, useState",
                1,
            )

    MP.write_text(text)
    print("patched MediaPlayer.tsx")

# Verify useCallback import
text = MP.read_text()
if "useCallback" not in text[:2500]:
    raise SystemExit("useCallback missing from imports — fix manually")
print("ok")
