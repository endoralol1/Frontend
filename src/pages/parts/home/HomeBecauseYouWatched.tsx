import { Listbox } from "@headlessui/react";
import React, { useEffect, useMemo, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router-dom";

import { Dropdown } from "@/components/form/Dropdown";
import { Icon, Icons } from "@/components/Icon";
import { MediaCard } from "@/components/media/MediaCard";
import { useIsMobile } from "@/hooks/useIsMobile";
import { CarouselNavButtons } from "@/pages/discover/components/CarouselNavButtons";
import { useDiscoverMedia } from "@/pages/discover/hooks/useDiscoverMedia";
import { useDiscoverStore } from "@/stores/discover";
import { useProgressStore } from "@/stores/progress";
import { shouldShowProgress } from "@/stores/progress/utils";
import { MediaItem } from "@/utils/mediaTypes";

const SOURCE_STORAGE_KEY = "__MW::becauseYouWatchedSource";

interface HomeBecauseYouWatchedProps {
  carouselRefs: React.MutableRefObject<{
    [key: string]: HTMLDivElement | null;
  }>;
  onShowDetails?: (media: MediaItem) => void;
}

function MediaCardSkeleton() {
  return (
    <div className="relative mt-4 group cursor-default rounded-xl p-2 bg-transparent transition-colors duration-300 w-[10rem] md:w-[11.5rem] h-auto">
      <div className="animate-pulse">
        <div className="w-full aspect-[2/3] bg-mediaCard-hoverBackground rounded-lg" />
        <div className="mt-2 h-4 bg-mediaCard-hoverBackground rounded w-3/4" />
      </div>
    </div>
  );
}

export function HomeBecauseYouWatched({
  carouselRefs,
  onShowDetails,
}: HomeBecauseYouWatchedProps) {
  const { t } = useTranslation();
  const browser = !!window.chrome;
  const { isMobile } = useIsMobile();
  const { setLastView } = useDiscoverStore();
  const isScrollingRef = useRef(false);

  const progressItems = useProgressStore((state) => state.items);

  const sources = useMemo(() => {
    return Object.entries(progressItems || {})
      .filter(([, item]) => shouldShowProgress(item).show)
      .sort((a, b) => (b[1].updatedAt ?? 0) - (a[1].updatedAt ?? 0))
      .map(([id, item]) => ({
        id,
        title: item.title || t("discover.carousel.title.loading"),
        type: item.type as "movie" | "show",
      }));
  }, [progressItems, t]);

  const [selectedId, setSelectedId] = useState<string>("");

  useEffect(() => {
    if (sources.length === 0) {
      setSelectedId("");
      return;
    }

    const saved = localStorage.getItem(SOURCE_STORAGE_KEY);
    const preferred =
      (saved && sources.find((source) => source.id === saved)) || sources[0];

    setSelectedId((current) => {
      if (current && sources.some((source) => source.id === current)) {
        return current;
      }
      return preferred.id;
    });
  }, [sources]);

  const selectedSource = sources.find((source) => source.id === selectedId);
  const isTVShow = selectedSource?.type === "show";
  const mediaType = isTVShow ? "tv" : "movie";

  const { media, isLoading } = useDiscoverMedia({
    contentType: "recommendations",
    mediaType,
    id: selectedId || undefined,
    mediaTitle: selectedSource?.title,
    isCarouselView: true,
    enabled: Boolean(selectedId),
  });

  const categorySlug = "home-because-you-watched";

  const handleWheel = React.useCallback(
    (e: React.WheelEvent) => {
      if (isScrollingRef.current) return;
      isScrollingRef.current = true;

      if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
        e.stopPropagation();
        e.preventDefault();
      }

      if (browser) {
        setTimeout(() => {
          isScrollingRef.current = false;
        }, 345);
      } else {
        isScrollingRef.current = false;
      }
    },
    [browser],
  );

  if (sources.length === 0) {
    return null;
  }

  const moreLink = selectedId
    ? `/discover/more/recommendations/${selectedId}/${mediaType}`
    : "/discover";

  return (
    <section className="relative mb-2">
      <div className="flex items-end justify-between gap-4 ml-2 md:ml-8 mt-2">
        <div className="flex flex-col pl-2 lg:pl-[68px] min-w-0">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h2 className="text-xl md:text-2xl font-bold text-white text-balance">
              {t("home.becauseYouWatched.prefix")}
            </h2>
            <Dropdown
              selectedItem={{
                id: selectedSource?.id || "",
                name: selectedSource?.title || "",
              }}
              setSelectedItem={(item) => {
                setSelectedId(item.id);
                localStorage.setItem(SOURCE_STORAGE_KEY, item.id);
              }}
              options={sources.map((source) => ({
                id: source.id,
                name: source.title,
              }))}
              side="left"
              className="!my-0"
              customButton={
                <button
                  type="button"
                  className="group inline-flex max-w-[min(100%,20rem)] items-center gap-2 rounded-xl border border-white/10 bg-mediaCard-hoverBackground/80 px-3 py-1.5 text-left shadow-sm backdrop-blur-sm transition-colors hover:border-white/20 hover:bg-mediaCard-hoverBackground"
                >
                  <span className="truncate text-base md:text-lg font-semibold text-type-link">
                    {selectedSource?.title}
                  </span>
                  <Icon
                    icon={Icons.CHEVRON_DOWN}
                    className="shrink-0 text-sm text-type-secondary transition-transform group-aria-expanded:rotate-180"
                  />
                </button>
              }
              customMenu={
                <Listbox.Options static className="py-1 max-h-64 overflow-auto">
                  {sources.map((opt) => (
                    <Listbox.Option
                      className={({ active }) =>
                        `cursor-pointer min-w-60 flex gap-3 items-center relative select-none py-2.5 px-4 mx-1 rounded-lg ${
                          active
                            ? "bg-background-secondaryHover text-type-link"
                            : "text-type-secondary"
                        }`
                      }
                      key={opt.id}
                      value={{ id: opt.id, name: opt.title }}
                    >
                      {({ selected }) => (
                        <>
                          <span className="flex flex-col min-w-0">
                            <span
                              className={`truncate ${selected ? "font-semibold text-white" : "font-normal"}`}
                            >
                              {opt.title}
                            </span>
                            <span className="text-xs text-type-secondary/80">
                              {opt.type === "show"
                                ? t("home.becauseYouWatched.show")
                                : t("home.becauseYouWatched.movie")}
                            </span>
                          </span>
                          {selected && (
                            <Icon
                              icon={Icons.CHECKMARK}
                              className="ml-auto text-xs text-type-link"
                            />
                          )}
                        </>
                      )}
                    </Listbox.Option>
                  ))}
                </Listbox.Options>
              }
            />
          </div>
          <Link
            to={moreLink}
            onClick={() =>
              setLastView({
                url: window.location.pathname,
                scrollPosition: window.scrollY,
              })
            }
            className="mt-1 flex w-fit items-center text-sm text-type-secondary hover:text-type-link transition-colors"
          >
            <span>{t("discover.carousel.more")}</span>
            <Icon className="text-sm ml-1" icon={Icons.ARROW_RIGHT} />
          </Link>
        </div>
      </div>

      <div className="relative overflow-hidden carousel-container md:pb-4">
        <div
          id={`carousel-${categorySlug}`}
          className="grid grid-flow-col auto-cols-max gap-4 pt-0 overflow-x-scroll scrollbar-none rounded-xl overflow-y-hidden md:pl-8 md:pr-8"
          ref={(el) => {
            carouselRefs.current[categorySlug] = el;
          }}
          onWheel={handleWheel}
        >
          <div className="lg:w-12" />

          {isLoading || media.length === 0
            ? Array.from({ length: 8 }).map((_, index) => (
                <MediaCardSkeleton
                  // eslint-disable-next-line react/no-array-index-key
                  key={`because-skeleton-${index}`}
                />
              ))
            : media.map((item) => (
                <div
                  onContextMenu={(e: React.MouseEvent<HTMLDivElement>) =>
                    e.preventDefault()
                  }
                  key={`${item.id}-${mediaType}`}
                  className="relative mt-4 group cursor-pointer user-select-none rounded-xl p-2 bg-transparent transition-colors duration-300 w-[10rem] md:w-[11.5rem] h-auto"
                >
                  <MediaCard
                    linkable
                    media={{
                      id: item.id.toString(),
                      title: item.title || item.name || "",
                      poster: item.poster_path
                        ? `https://image.tmdb.org/t/p/w342${item.poster_path}`
                        : "/placeholder.png",
                      type: isTVShow ? "show" : "movie",
                      year: isTVShow
                        ? item.first_air_date
                          ? parseInt(item.first_air_date.split("-")[0], 10)
                          : undefined
                        : item.release_date
                          ? parseInt(item.release_date.split("-")[0], 10)
                          : undefined,
                    }}
                    onShowDetails={onShowDetails}
                  />
                </div>
              ))}

          <div className="lg:w-12" />
        </div>

        {!isMobile && (
          <CarouselNavButtons
            categorySlug={categorySlug}
            carouselRefs={carouselRefs}
          />
        )}
      </div>
    </section>
  );
}
