import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import BrandingHead from '@/components/branding-head';
import { PageBreadcrumbBar } from '@/components/page-breadcrumb-bar';
import { useTranslation } from '@/hooks/use-translation';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { t } = useTranslation();

    return (
        <AppShell variant="sidebar">
            <BrandingHead />
            <a
                href="#main-content"
                className="bg-background focus:ring-ring sr-only rounded-md px-3 py-2 focus:not-sr-only focus:absolute focus:z-50 focus:ring-2"
            >
                {t('common.nav.skip')}
            </a>
            <AppSidebar />
            <AppContent
                id="main-content"
                variant="sidebar"
                className="min-w-0 overflow-x-clip"
            >
                <AppSidebarHeader />
                <PageBreadcrumbBar breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
