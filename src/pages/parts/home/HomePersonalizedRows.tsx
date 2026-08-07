import React from "react";

import { MediaItem } from "@/utils/mediaTypes";

import { HomeBecauseYouWatched } from "./HomeBecauseYouWatched";
import { HomeOnlyOnNetwork } from "./HomeOnlyOnNetwork";

interface HomePersonalizedRowsProps {
  carouselRefs: React.MutableRefObject<{
    [key: string]: HTMLDivElement | null;
  }>;
  onShowDetails?: (media: MediaItem) => void;
}

/** Homepage rows: Because you watched → Only on [network]. */
export function HomePersonalizedRows({
  carouselRefs,
  onShowDetails,
}: HomePersonalizedRowsProps) {
  return (
    <div className="flex flex-col gap-6 md:gap-8 pt-2 pb-4">
      <HomeBecauseYouWatched
        carouselRefs={carouselRefs}
        onShowDetails={onShowDetails}
      />
      <HomeOnlyOnNetwork
        carouselRefs={carouselRefs}
        onShowDetails={onShowDetails}
      />
    </div>
  );
}
