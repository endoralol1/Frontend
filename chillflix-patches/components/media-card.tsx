import { ComponentProps } from "react"

import { cn } from "@/lib/utils"

/**
 * Same poster shape, softer corners, slight grow on hover.
 * Scale wrapper is separate from the rounded overflow shell so corners
 * stay curved while the card gets bigger.
 */
const Root: React.FC<ComponentProps<"div">> = ({
  className,
  children,
  ...props
}) => {
  return (
    <div className={cn("relative aspect-poster", className)} {...props}>
      <div
        className={cn(
          "media-card-root absolute inset-0 origin-center",
          "transform-gpu will-change-transform",
          "transition-transform duration-300 ease-out",
          "group-hover:z-10 group-hover:scale-[1.08]"
        )}
      >
        <div className="media-card-shell">{children}</div>
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
      <div className="pointer-events-auto w-full rounded-b-[1.25rem] border-t border-white/[0.07] bg-[rgb(23_23_27/28%)] px-2.5 py-2 md:px-3 md:py-2.5">
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
