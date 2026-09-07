import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    return (
        <SidebarProvider
            defaultOpen={isOpen}
            /*
             * Set here rather than in `ui/sidebar.tsx`, which is vendored and
             * must not be hand-edited — the provider reads these off its own
             * style, which is the supported way to size the rail.
             */
            style={
                {
                    '--sidebar-width': '16.25rem',
                    '--sidebar-width-icon': '3.25rem',
                } as React.CSSProperties
            }
        >
            {children}
        </SidebarProvider>
    );
}
