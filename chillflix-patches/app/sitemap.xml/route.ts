import { SITEMAP_REVALIDATE_SECONDS } from "@/lib/sitemap/constants"
import { getCachedSitemapCatalog } from "@/lib/sitemap/catalog-cache"
import { buildSitemapIndexEntries } from "@/lib/sitemap/files"
import { serializeSitemapIndex, toSitemapResponse } from "@/lib/sitemap/xml"

export const revalidate = SITEMAP_REVALIDATE_SECONDS

/** Sitemap index pointing at static / movies / tv chunk files. */
export async function GET() {
    const catalog = await getCachedSitemapCatalog()
    const xml = serializeSitemapIndex(buildSitemapIndexEntries(catalog))
    return toSitemapResponse(xml)
}
