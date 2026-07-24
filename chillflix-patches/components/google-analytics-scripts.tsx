import { DeferredGoogleAnalytics } from "@/components/deferred-google-analytics"
import { getMeasurementId } from "@/lib/analytics-config"
import { shouldSkipAnalytics } from "@/lib/should-skip-analytics"

export async function GoogleAnalyticsScripts() {
    const gaId = await getMeasurementId()
    if (!gaId || shouldSkipAnalytics()) return null

    return <DeferredGoogleAnalytics gaId={gaId} />
}
