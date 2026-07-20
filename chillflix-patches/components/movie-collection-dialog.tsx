"use client"

import Link from "next/link"
import { MediaDetailLink } from "@/components/media-detail-link"
import { markDetailModalOpen } from "@/lib/detail-modal-session"
import { pages } from "@/config"
import { useDialog } from "@/hooks"
import { DetailedCollection } from "@/tmdb/models"

import { sortByReleaseDate } from "@/lib/utils"
import { buttonVariants } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { ScrollArea } from "@/components/ui/scroll-area"
import { MediaBackdrop } from "@/components/media-backdrop"
import { MediaMiniDetail } from "@/components/media-mini-detail"
import { MediaPoster } from "@/components/media-poster"

interface MovieCollectionDialogProps {
  collection: DetailedCollection
  collectionId: number
}

export const MovieCollectionDialog: React.FC<MovieCollectionDialogProps> = ({
  collection: { name, overview, parts },
  collectionId,
}) => {
  const [open, setOpen] = useDialog()

  return (
    <Dialog open={open} onOpenChange={setOpen} modal>
      <DialogTrigger className={buttonVariants()}>
        View the collection
      </DialogTrigger>

      <DialogContent
        onOpenAutoFocus={(event) => event.preventDefault()}
        overlayClassName="z-[10050] bg-black/60"
        className="z-[10051] max-w-screen-lg"
      >
        <DialogHeader>
          <DialogTitle>{name}</DialogTitle>
          {overview ? (
            <DialogDescription className="text-muted-foreground line-clamp-3">
              {overview}
            </DialogDescription>
          ) : null}
        </DialogHeader>

        <ScrollArea className="max-h-[min(70dvh,640px)] md:pr-4">
          <div className="grid gap-4 md:grid-cols-2">
            {sortByReleaseDate(parts).map((part) => (
              <MediaDetailLink href={`${pages.movie.root.link}/${part.id}`} key={part.id}>
                <MediaMiniDetail.Root className="rounded-md border">
                  <MediaMiniDetail.Backdrop>
                    <MediaBackdrop
                      image={part.backdrop_path}
                      alt={part.title}
                      className="rounded-b-none"
                      size="w780"
                    />
                  </MediaMiniDetail.Backdrop>

                  <MediaMiniDetail.Hero>
                    <MediaMiniDetail.Poster>
                      <MediaPoster image={part.poster_path} alt={part.title} />
                    </MediaMiniDetail.Poster>

                    <div className="space-y-1">
                      <MediaMiniDetail.Title>
                        {part.title}
                      </MediaMiniDetail.Title>
                      <MediaMiniDetail.Overview>
                        {part.overview}
                      </MediaMiniDetail.Overview>
                    </div>
                  </MediaMiniDetail.Hero>
                </MediaMiniDetail.Root>
              </MediaDetailLink>
            ))}
          </div>
        </ScrollArea>

        <p className="text-center text-xs text-muted-foreground">
          <Link
            href={`${pages.collection.root.link}/${collectionId}`}
            className="underline-offset-4 hover:underline"
            prefetch={false}
            scroll={false}
            onClick={() => markDetailModalOpen()}
          >
            Open collection page
          </Link>
        </p>
      </DialogContent>
    </Dialog>
  )
}
