import type { Auth } from '@/types/auth';
import type { Team } from '@/types/teams';

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
            currentTeam: Team | null;
            teams: Team[];
            /**
             * The abilities the navigation gates on — not the whole permission
             * set. Hiding a link is a convenience; the route's policy refuses.
             */
            permissions: Record<string, boolean>;
            [key: string]: unknown;
        };
    }
}
