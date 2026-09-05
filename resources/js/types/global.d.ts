import type { Auth } from '@/types/auth';
import type { AccountContext } from '@/types/account';

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
            [key: string]: unknown;
        };
    }
}
