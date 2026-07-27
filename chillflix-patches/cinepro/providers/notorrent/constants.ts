export const NOTORRENT_ADDON_BASE = 'https://addon.notorrent2.workers.dev';

export const NOTORRENT_HEADERS = {
    'User-Agent':
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    Accept: 'application/json, text/plain, */*',
    'Accept-Language': 'en-US,en;q=0.9',
    Origin: NOTORRENT_ADDON_BASE,
    Referer: `${NOTORRENT_ADDON_BASE}/`
} as const;

// Legacy host NoTorrent used to embed from. Current free-trial / All Players
// routes go through nextgencloudfabric / ciniverse / hostinger instead.
export const NOTORRENT_STREAM_REFERER = 'https://nextgencloudfabric.com/';
