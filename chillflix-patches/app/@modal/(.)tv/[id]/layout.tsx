import { MediaDetailModalShell } from "@/components/media-detail-modal-shell"
import { isMediaDetailId } from "@/lib/media-detail-id"

export default function ModalTvLayout({
  children,
  params,
}: {
  children: React.ReactNode
  params: { id: string }
}) {
  if (!isMediaDetailId(params.id)) {
    return null
  }

  return <MediaDetailModalShell>{children}</MediaDetailModalShell>
}
