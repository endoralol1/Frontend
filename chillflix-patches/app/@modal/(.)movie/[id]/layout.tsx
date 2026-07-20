import { MediaDetailModalShell } from "@/components/media-detail-modal-shell"
import { isMediaDetailId } from "@/lib/media-detail-id"
import MovieDetailLayout from "../../../(detail)/movie/[id]/layout"

export default async function ModalMovieLayout(
  props: React.ComponentProps<typeof MovieDetailLayout>
) {
  if (!isMediaDetailId(props.params.id)) {
    return null
  }

  return (
    <MediaDetailModalShell>
      <MovieDetailLayout {...props} />
    </MediaDetailModalShell>
  )
}
