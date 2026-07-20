import { ComponentProps } from "react"
import Image from "next/image"
import { PosterSize, wsrvImage } from "@/tmdb/utils"

import { cn } from "@/lib/utils"
import { Icons } from "@/components/icons"

interface MediaPosterProps extends ComponentProps<"div"> {
  image?: string
  /** Use `card` for carousel/grid posters; TMDB sizes for detail/hero. */
  size?: PosterSize | "card"
  alt: string
  priority?: boolean
}

export const MediaPoster: React.FC<MediaPosterProps> = ({
  image,
  size = "card",
  alt,
  className,
  priority,
  ...props
}) => {
  const src = image
    ? size === "card"
      ? wsrvImage.posterCard(image)
      : wsrvImage.poster(image, size)
    : null

  if (!src) {
    return (
      <div
        className={cn(
          "size-full rounded-[1.25rem] bg-muted text-muted-foreground",
          className
        )}
        {...props}
      >
        <div className="grid size-full place-items-center">
          <Icons.Logo className="size-12" />
        </div>
      </div>
    )
  }

  return (
    <div className={cn("relative size-full", className)} {...props}>
      <Image
        className="rounded-[1.25rem] bg-muted object-cover"
        src={src}
        alt={alt}
        priority={priority}
        unoptimized
        fill
        sizes="(max-width: 768px) 45vw, (max-width: 1280px) 28vw, 220px"
      />
    </div>
  )
}
