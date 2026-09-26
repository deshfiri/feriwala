// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');

    return {
        usePage: () => ({ props: { translations: {} } }),
        Head: () => createElement('head'),
    };
});

const { default: Video } = await import('./video');

const posterMedia = {
    public_id: 'media_poster',
    url: 'https://cdn.example.test/poster.jpg',
    width: 1280,
    height: 720,
    mime_type: 'image/jpeg',
    alt: 'A supplier packing an order',
};

/**
 * The one section that embeds a third party (§34, Stage 7 addendum) —
 * proving the player never loads until asked for, and that its `src` is
 * always a rebuilt, canonical embed URL rather than the stored `video_url`
 * passed straight into an iframe.
 */
describe('the video section', () => {
    it('shows only the poster until the play button is pressed, then embeds the canonical URL', async () => {
        const user = userEvent.setup();

        render(
            <Video
                content={{
                    heading: 'See it in action',
                    video_url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    poster_media: posterMedia,
                }}
            />,
        );

        expect(screen.queryByTitle('See it in action')).not.toBeInTheDocument();
        expect(
            screen.getByAltText('A supplier packing an order'),
        ).toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'public.video.play' }),
        );

        const iframe = screen.getByTitle('See it in action');
        expect(iframe).toHaveAttribute(
            'src',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0',
        );
    });

    it('falls back to a plain link rather than embedding an unrecognized path on an allowed host', () => {
        render(
            <Video
                content={{
                    video_url: 'https://www.youtube.com/channel/UCsomeid',
                    poster_media: posterMedia,
                }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'public.video.play' }),
        ).not.toBeInTheDocument();

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute(
            'href',
            'https://www.youtube.com/channel/UCsomeid',
        );
        expect(screen.queryByRole('iframe')).not.toBeInTheDocument();
    });
});
