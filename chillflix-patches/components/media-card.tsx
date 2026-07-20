import { ComponentProps } from "react"

import { cn } from "@/lib/utils"

/**
 * Layout slot keeps a stable 2:3 footprint in the grid/carousel.
 * The inner shell scales on hover so the poster grows without changing
 * aspect ratio or getting cropped by the card’s own overflow box.
 */
const Root: React.FC<ComponentProps<"div">> = ({
  className,
  children,
  ...props
}) => {
  return (
    <div
      className={cn("relative aspect-poster", className)}
      {...props}
    >
      <div
        className={cn(
          "media-card-root absolute inset-0 overflow-hidden rounded-2xl",
          "origin-center transform-gpu will-change-transform",
          "ring-1 ring-white/[0.08]",
          "shadow-[0_8px_22px_rgba(0,0,0,0.28)]",
          "transition-[transform,box-shadow,ring-color] duration-300 ease-out",
          "md:group-hover:z-10 md:group-hover:scale-[1.04]",
          "md:group-hover:shadow-[0_16px_36px_rgba(0,0,0,0.42)]",
          "md:group-hover:ring-primary/20"
        )}
      >
        {children}
      </div>
    </div>
  )
}

const Content: React.FC<ComponentProps<"div">> = ({
  className,
  children,
  ...props
}) => {
  return (
    <div
      className={cn(
        "media-card-content overlay flex items-end transition-opacity duration-300",
        className
      )}
      {...props}
    >
      <div className="pointer-events-auto w-full rounded-b-2xl border-t border-white/[0.07] bg-[rgb(23_23_27/28%)] px-2.5 py-2 md:px-3 md:py-2.5">
        {children}
      </div>
    </div>
  )
}

const Title: React.FC<ComponentProps<"h2">> = ({ className, ...props }) => {
  return (
    <h2
      className={cn(
        "media-card-title line-clamp-2 text-[14px] font-semibold leading-[1.28] md:text-[15px]",
        className
      )}
      {...props}
    />
  )
}

const Excerpt: React.FC<ComponentProps<"p">> = ({ className, ...props }) => {
  return (
    <p
      className={cn(
        "line-clamp-1 text-[11px] font-medium text-white/50 md:text-xs",
        className
      )}
      {...props}
    />
  )
}

export const MediaCard = {
  Root,
  Content,
  Title,
  Excerpt,
}
