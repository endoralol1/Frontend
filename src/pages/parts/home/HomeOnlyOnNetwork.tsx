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
import { MediaItem } from "@/utils/mediaTypes";

const NETWORK_STORAGE_KEY = "__MW::onlyOnNetwork";

interface NetworkOption {
  id: string;
  name: string;
  shortName: string;
  image: string;
}

/** Curated homepage networks — Netflix first, matching site platform art. */
const HOME_NETWORKS: NetworkOption[] = [
  { id: "8", name: "Netflix", shortName: "Netflix", image: "netflix" },
  { id: "337", name: "Disney Plus", shortName: "Disney+", image: "disney" },
  {
    id: "1899",
    name: "Max",
    shortName: "Max",
    image: "max",
  },
  {
    id: "10",
    name: "Amazon Prime Video",
    shortName: "Prime Video",
    image: "prime",
  },
  { id: "15", name: "Hulu", shortName: "Hulu", image: "hulu" },
  { id: "2", name: "Apple TV+", shortName: "Apple TV+", image: "appletv" },
  {
    id: "531",
    name: "Paramount Plus",
    shortName: "Paramount+",
    image: "paramount",
  },
];

interface HomeOnlyOnNetworkProps {
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

function NetworkMark({ network }: { network: NetworkOption }) {
  return (
    <img
      src={`/platforms/${network.image}.png`}
      alt=""
      className="h-5 w-5 rounded-md object-contain bg-black/40"
      loading="lazy"
    />
  );
}

export function HomeOnlyOnNetwork({
  carouselRefs,
  onShowDetails,
}: HomeOnlyOnNetworkProps) {
  const { t } = useTranslation();
  const browser = !!window.chrome;
  const { isMobile } = useIsMobile();
  const { setLastView } = useDiscoverStore();
  const isScrollingRef = useRef(false);

  const [selectedId, setSelectedId] = useState<string>(() => {
    const saved = localStorage.getItem(NETWORK_STORAGE_KEY);
    if (saved && HOME_NETWORKS.some((network) => network.id === saved)) {
      return saved;
    }
    return HOME_NETWORKS[0].id;
  });

  const selectedNetwork = useMemo(
    () =>
      HOME_NETWORKS.find((network) => network.id === selectedId) ||
      HOME_NETWORKS[0],
    [selectedId],
  );

  useEffect(() => {
    localStorage.setItem(NETWORK_STORAGE_KEY, selectedNetwork.id);
  }, [selectedNetwork.id]);

  const { media, isLoading } = useDiscoverMedia({
    contentType: "provider",
    mediaType: "movie",
    id: selectedNetwork.id,
    providerName: selectedNetwork.name,
    isCarouselView: true,
  });

  const categorySlug = "home-only-on-network";

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

  const moreLink = `/discover/more/provider/${selectedNetwork.id}/movie`;

  return (
    <section className="relative mb-2">
      <div className="flex items-end justify-between gap-4 ml-2 md:ml-8 mt-2">
        <div className="flex flex-col pl-2 lg:pl-[68px] min-w-0">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h2 className="text-xl md:text-2xl font-bold text-white text-balance">
              {t("home.onlyOnNetwork.prefix")}
            </h2>
            <Dropdown
              selectedItem={{
                id: selectedNetwork.id,
                name: selectedNetwork.shortName,
              }}
              setSelectedItem={(item) => setSelectedId(item.id)}
              options={HOME_NETWORKS.map((network) => ({
                id: network.id,
                name: network.shortName,
              }))}
              side="left"
              className="!my-0"
              customButton={
                <button
                  type="button"
                  className="group inline-flex items-center gap-2.5 rounded-xl border border-white/10 bg-mediaCard-hoverBackground/80 px-3 py-1.5 text-left shadow-sm backdrop-blur-sm transition-colors hover:border-white/20 hover:bg-mediaCard-hoverBackground"
                >
                  <NetworkMark network={selectedNetwork} />
                  <span className="text-base md:text-lg font-semibold text-white">
                    {selectedNetwork.shortName}
                  </span>
                  <Icon
                    icon={Icons.CHEVRON_DOWN}
                    className="shrink-0 text-sm text-type-secondary"
                  />
                </button>
              }
              customMenu={
                <Listbox.Options static className="py-1 max-h-72 overflow-auto">
                  {HOME_NETWORKS.map((opt) => (
                    <Listbox.Option
                      className={({ active }) =>
                        `cursor-pointer min-w-56 flex gap-3 items-center relative select-none py-2.5 px-4 mx-1 rounded-lg ${
                          active
                            ? "bg-background-secondaryHover text-type-link"
                            : "text-type-secondary"
                        }`
                      }
                      key={opt.id}
                      value={{ id: opt.id, name: opt.shortName }}
                    >
                      {({ selected }) => (
                        <>
                          <NetworkMark network={opt} />
                          <span
                            className={`truncate ${selected ? "font-semibold text-white" : "font-normal"}`}
                          >
                            {opt.shortName}
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
                  key={`network-skeleton-${index}`}
                />
              ))
            : media.map((item) => (
                <div
                  onContextMenu={(e: React.MouseEvent<HTMLDivElement>) =>
                    e.preventDefault()
                  }
                  key={`network-${selectedNetwork.id}-${item.id}`}
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
                      type: "movie",
                      year: item.release_date
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
