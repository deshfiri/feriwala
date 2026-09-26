/**
 * Turns a `video` section's already-host-validated `video_url`
 * (App\Domain\Cms\Rules\SafeVideoUrl only checks scheme + host, never the
 * path) into a canonical, embeddable player URL the frontend builds itself
 * from an extracted numeric/alphanumeric id — never by passing the stored
 * string straight into an iframe `src`, so a query string on the stored URL
 * can never reach the embed unexamined.
 */

const YOUTUBE_HOSTS = new Set([
    'www.youtube.com',
    'youtube.com',
    'youtube-nocookie.com',
    'www.youtube-nocookie.com',
]);

const VIMEO_HOSTS = new Set(['vimeo.com', 'player.vimeo.com']);

export function resolveVideoEmbedUrl(rawUrl: string): string | null {
    let parsed: URL;

    try {
        parsed = new URL(rawUrl);
    } catch {
        return null;
    }

    if (parsed.protocol !== 'https:') {
        return null;
    }

    const host = parsed.hostname.toLowerCase();

    if (YOUTUBE_HOSTS.has(host)) {
        const id = youtubeVideoId(parsed);

        return id ? `https://www.youtube-nocookie.com/embed/${id}?rel=0` : null;
    }

    if (VIMEO_HOSTS.has(host)) {
        const id = vimeoVideoId(parsed);

        return id ? `https://player.vimeo.com/video/${id}` : null;
    }

    return null;
}

function youtubeVideoId(url: URL): string | null {
    const fromQuery = url.searchParams.get('v');

    if (fromQuery && /^[\w-]{6,}$/.test(fromQuery)) {
        return fromQuery;
    }

    const embedMatch = url.pathname.match(/\/embed\/([\w-]{6,})/);

    return embedMatch ? embedMatch[1] : null;
}

function vimeoVideoId(url: URL): string | null {
    const match = url.pathname.match(/(\d{5,})/);

    return match ? match[1] : null;
}

/** A short, human label for the fallback "Watch on ..." link — never renders the raw URL. */
export function videoProviderLabel(rawUrl: string): string {
    try {
        const host = new URL(rawUrl).hostname.toLowerCase();

        if (YOUTUBE_HOSTS.has(host)) {
            return 'YouTube';
        }

        if (VIMEO_HOSTS.has(host)) {
            return 'Vimeo';
        }

        return host;
    } catch {
        return '';
    }
}
