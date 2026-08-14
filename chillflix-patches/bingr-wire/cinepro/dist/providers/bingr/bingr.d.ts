import { BaseProvider } from '@omss/framework';
import type { ProviderCapabilities, ProviderMediaObject, ProviderResult } from '@omss/framework';
export declare class BingrProvider extends BaseProvider {
    readonly id = "bingr";
    readonly name = "Bingr";
    readonly enabled = true;
    readonly BASE_URL = "https://bingr.one";
    readonly HEADERS: Record<string, string>;
    readonly capabilities: ProviderCapabilities;
    getMovieSources(media: ProviderMediaObject): Promise<ProviderResult>;
    getTVSources(media: ProviderMediaObject): Promise<ProviderResult>;
    private cacheKey;
    private emptyResult;
    private postStream;
    private mapSubtitles;
    private buildPlayable;
    private resolve;
    healthCheck(): Promise<boolean>;
}
