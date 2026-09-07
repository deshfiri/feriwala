import type { Auth } from '@/types/auth';
import type { AccountContext } from '@/types/account';
import type { HeaderNotification } from '@/types/notification';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /**
             * The one business account this person works in, or null for
             * platform staff who have none. Never a list: there is nothing to
             * switch between (D1).
             */
            account: AccountContext | null;
            /**
             * The abilities the navigation gates on — not the whole permission
             * set. Hiding a link is a convenience; the route's policy refuses.
             */
            permissions: Record<string, boolean>;
            /**
             * The most recent notifications for the header bell, capped
             * server-side. Empty for a guest, and never invented (D20).
             */
            notifications: HeaderNotification[];
            /**
             * Counted server-side rather than derived from the capped list —
             * someone with twelve unread should not be told they have ten.
             */
            unreadNotificationCount: number;
            [key: string]: unknown;
        };
    }
}
