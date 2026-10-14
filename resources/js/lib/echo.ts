import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { xsrfToken } from '@/lib/xsrf';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

let instance: Echo<'reverb'> | null = null;

/**
 * The one WebSocket connection to the self-hosted Reverb server, opened on first
 * use and shared by everything on the page.
 *
 * Private channels are authorised against the signed-in session at
 * `/broadcasting/auth`. The app sends no `csrf-token` meta tag, so the default
 * authoriser would be refused with a 419 — this one sends the XSRF cookie as a
 * header instead, which is how every other fetch in the app proves itself.
 *
 * Returns null when no Reverb key is configured: a deployment without a
 * socket server must still load and work, just without live updates.
 */
export function echo(): Echo<'reverb'> | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    if (instance === null) {
        window.Pusher = Pusher;

        const scheme =
            (import.meta.env.VITE_REVERB_SCHEME as string | undefined) ??
            window.location.protocol.replace(':', '');
        const port = Number(
            import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80),
        );

        instance = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost:
                (import.meta.env.VITE_REVERB_HOST as string | undefined) ??
                window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            authorizer: (channel) => ({
                authorize: (socketId, callback) => {
                    fetch('/broadcasting/auth', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': xsrfToken(),
                        },
                        body: JSON.stringify({
                            socket_id: socketId,
                            channel_name: channel.name,
                        }),
                    })
                        .then((response) => {
                            if (!response.ok) {
                                throw new Error(
                                    `Channel authorisation failed (${response.status})`,
                                );
                            }

                            return response.json();
                        })
                        .then((data) => callback(null, data))
                        .catch((error: Error) => callback(error, null));
                },
            }),
        });
    }

    return instance;
}
