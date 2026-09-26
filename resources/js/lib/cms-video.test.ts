import { describe, expect, it } from 'vitest';
import { resolveVideoEmbedUrl, videoProviderLabel } from './cms-video';

describe('resolveVideoEmbedUrl', () => {
    it('resolves a YouTube watch URL to the privacy-enhanced embed URL', () => {
        expect(
            resolveVideoEmbedUrl(
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ),
        ).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0');
    });

    it('resolves a YouTube embed URL already in embed form', () => {
        expect(
            resolveVideoEmbedUrl(
                'https://youtube-nocookie.com/embed/dQw4w9WgXcQ',
            ),
        ).toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0');
    });

    it('resolves a bare Vimeo URL to the player URL', () => {
        expect(resolveVideoEmbedUrl('https://vimeo.com/76979871')).toBe(
            'https://player.vimeo.com/video/76979871',
        );
    });

    it('resolves a Vimeo player URL already in player form', () => {
        expect(
            resolveVideoEmbedUrl('https://player.vimeo.com/video/76979871'),
        ).toBe('https://player.vimeo.com/video/76979871');
    });

    it('returns null for an allowed host with no extractable video id', () => {
        expect(
            resolveVideoEmbedUrl('https://www.youtube.com/channel/UCsomeid'),
        ).toBeNull();
    });

    it('returns null for a non-https URL', () => {
        expect(
            resolveVideoEmbedUrl('http://www.youtube.com/watch?v=abcdefghijk'),
        ).toBeNull();
    });

    it('returns null for a host outside the allow-list', () => {
        expect(
            resolveVideoEmbedUrl('https://evil.example.com/watch?v=abcdefghijk'),
        ).toBeNull();
    });

    it('returns null for an unparseable URL', () => {
        expect(resolveVideoEmbedUrl('not a url')).toBeNull();
    });
});

describe('videoProviderLabel', () => {
    it('labels YouTube and Vimeo hosts by name', () => {
        expect(
            videoProviderLabel('https://www.youtube.com/watch?v=x'),
        ).toBe('YouTube');
        expect(videoProviderLabel('https://vimeo.com/1')).toBe('Vimeo');
    });

    it('falls back to the bare hostname for anything else, never the raw URL', () => {
        expect(videoProviderLabel('https://example.com/video/1')).toBe(
            'example.com',
        );
    });

    it('returns an empty string for an unparseable URL rather than throwing', () => {
        expect(videoProviderLabel('not a url')).toBe('');
    });
});
