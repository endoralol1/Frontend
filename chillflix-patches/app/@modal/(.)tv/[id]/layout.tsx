import { MediaDetailModalShell } from "@/components/media-detail-modal-shell"
import { isMediaDetailId } from "@/lib/media-detail-id"
import TvDetailLayout from "../../../(detail)/tv/[id]/(tabs)/layout"

export default async function ModalTvLayout(
  props: React.ComponentProps<typeof TvDetailLayout>
) {
  if (!isMediaDetailId(props.params.id)) {
    return null
  }

  return (
    <MediaDetailModalShell>
      <TvDetailLayout {...props} />
    </MediaDetailModalShell>
  )
}
