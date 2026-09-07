import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import SectionCard from '@/components/section-card';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('common.appearance.label')} />

            <h1 className="sr-only">{t('common.appearance.label')}</h1>

            <SectionCard
                title={t('common.appearance.label')}
                description={t('common.appearance.description')}
            >
                <div className="space-y-2">
                    <AppearanceTabs />

                    <p className="text-muted-foreground text-xs">
                        {t('common.appearance.system_hint')}
                    </p>
                </div>
            </SectionCard>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
