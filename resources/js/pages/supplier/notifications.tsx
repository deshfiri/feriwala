import { Form, Head } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import SubmitButton from '@/components/forms/submit-button';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import EmptyState from '@/components/states/empty-state';
import { useTranslation } from '@/hooks/use-translation';
import { read } from '@/routes/supplier/notifications';

type Item = {
    id: string;
    title: string;
    description: string;
    note: string | null;
    read: boolean;
    created_at: string;
};

/**
 * The wording arrives already translated: event names contain dots, which the
 * browser-side `t()` would split on, so the server resolves them (see
 * `SupplierLifecycleNotification::line()`).
 */
export default function SupplierNotifications({
    notifications,
}: {
    notifications: Item[];
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('supplier.notifications.title')} />

            <div className="space-y-6">
                <PageHeader
                    title={t('supplier.notifications.title')}
                    actions={
                        notifications.some((item) => !item.read) && (
                            <Form
                                {...read.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <SubmitButton
                                        processing={processing}
                                        variant="outline"
                                        size="sm"
                                    >
                                        {t('supplier.notifications.mark_read')}
                                    </SubmitButton>
                                )}
                            </Form>
                        )
                    }
                />

                {notifications.length === 0 ? (
                    <EmptyState
                        icon={Bell}
                        title={t('supplier.notifications.empty_title')}
                        description={t(
                            'supplier.notifications.empty_description',
                        )}
                    />
                ) : (
                    <SectionCard>
                        <ul className="divide-border divide-y">
                            {notifications.map((item) => (
                                <li
                                    key={item.id}
                                    className="space-y-1 py-3 first:pt-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span
                                            className={
                                                item.read
                                                    ? 'text-sm'
                                                    : 'text-sm font-semibold'
                                            }
                                        >
                                            {item.title}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {new Date(
                                                item.created_at,
                                            ).toLocaleString(locale)}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        {item.note ?? item.description}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}
            </div>
        </>
    );
}
