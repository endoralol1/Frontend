import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

import { Icon, Icons } from "@/components/Icon";
import { Transition } from "@/components/utils/Transition";
import { useOverlayRouter } from "@/hooks/useOverlayRouter";
import { playerStatus } from "@/stores/player/slices/source";
import { usePlayerStore } from "@/stores/player/store";

/** How long buffering must last before we nudge the viewer. */
const STUCK_BUFFERING_MS = 8_000;

/**
 * Small bubble near Settings when playback looks stuck (black screen / endless
 * spinner). Tapping opens Settings → Sources so people try another provider.
 */
export function ChangeSourceHint() {
  const { t } = useTranslation();
  const settingsRouter = useOverlayRouter("settings");
  const status = usePlayerStore((s) => s.status);
  const isLoading = usePlayerStore((s) => s.mediaPlaying.isLoading);
  const hasPlayedOnce = usePlayerStore((s) => s.mediaPlaying.hasPlayedOnce);
  const sourceId = usePlayerStore((s) => s.sourceId);
  const hasOpenOverlay = usePlayerStore((s) => s.hasOpenOverlay);

  const [stuck, setStuck] = useState(false);
  const [dismissedForSource, setDismissedForSource] = useState<string | null>(
    null,
  );
  const loadingSinceRef = useRef<number | null>(null);

  useEffect(() => {
    const watching =
      status === playerStatus.PLAYING && Boolean(sourceId) && isLoading;

    if (!watching) {
      loadingSinceRef.current = null;
      setStuck(false);
      return;
    }

    if (loadingSinceRef.current == null) {
      loadingSinceRef.current = Date.now();
    }

    // First spin-up can be slower than mid-stream stalls.
    const waitMs = hasPlayedOnce
      ? STUCK_BUFFERING_MS
      : STUCK_BUFFERING_MS + 4_000;
    const remaining = Math.max(
      0,
      waitMs - (Date.now() - loadingSinceRef.current),
    );
    const timer = window.setTimeout(() => {
      setStuck(true);
    }, remaining);

    return () => window.clearTimeout(timer);
  }, [status, isLoading, sourceId, hasPlayedOnce]);

  // Reset dismiss when the active source changes.
  useEffect(() => {
    setDismissedForSource(null);
  }, [sourceId]);

  const visible =
    stuck &&
    Boolean(sourceId) &&
    status === playerStatus.PLAYING &&
    isLoading &&
    !hasOpenOverlay &&
    dismissedForSource !== sourceId;

  const openSources = () => {
    settingsRouter.open();
    settingsRouter.navigate("/source");
  };

  return (
    <Transition
      animation="slide-up"
      show={visible}
      className="pointer-events-none absolute inset-x-0 bottom-[5.5rem] z-[60] flex justify-center px-4 sm:bottom-24 sm:justify-end sm:pr-6 lg:pr-10"
    >
      <div className="pointer-events-auto relative max-w-[min(100%,18rem)]">
        <button
          type="button"
          onClick={openSources}
          className="flex w-full items-center gap-2.5 rounded-full border border-white/15 bg-black/80 px-3.5 py-2 text-left text-white shadow-lg backdrop-blur-md transition hover:bg-black/90"
        >
          <span className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-white/10">
            <Icon icon={Icons.GEAR} className="text-sm" />
          </span>
          <span className="min-w-0 flex-1">
            <span className="block text-xs font-semibold leading-tight">
              {t("player.changeSourceHint.title")}
            </span>
            <span className="block text-[11px] leading-snug text-white/70">
              {t("player.changeSourceHint.text")}
            </span>
          </span>
          <Icon
            icon={Icons.CHEVRON_RIGHT}
            className="flex-shrink-0 text-sm text-white/60"
          />
        </button>
        <button
          type="button"
          aria-label={t("player.changeSourceHint.dismiss")}
          onClick={(e) => {
            e.stopPropagation();
            setDismissedForSource(sourceId);
          }}
          className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-white/20 text-[10px] text-white backdrop-blur hover:bg-white/30"
        >
          ✕
        </button>
        {/* caret pointing toward Settings in the control bar */}
        <span
          aria-hidden
          className="absolute left-1/2 top-full h-0 w-0 -translate-x-1/2 border-x-[6px] border-t-[6px] border-x-transparent border-t-black/80 sm:left-auto sm:right-8 sm:translate-x-0"
        />
      </div>
    </Transition>
  );
}
