import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import SupplierAuthLayout from '@/layouts/supplier-auth-layout';
import SupplierLayout from '@/layouts/supplier-layout';
import { syncDocumentLocale } from '@/lib/sync-document-locale';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

router.on('success', (event) => syncDocumentLocale(event.detail.page.props));

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // The Supplier account domain (D25) has its own shells, visibly
            // separate from the Client/Partner ERP.
            case name.startsWith('supplier/auth/'):
                return SupplierAuthLayout;
            case name.startsWith('supplier/'):
                return SupplierLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        // The brand token, so the bar re-themes with the page instead of
        // carrying a grey that belongs to neither mode.
        color: 'var(--brand)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
