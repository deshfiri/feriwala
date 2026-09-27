import { Form, Head, Link } from '@inertiajs/react';
import { CheckCircle2, Circle } from 'lucide-react';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import SubmitButton from '@/components/forms/submit-button';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { send } from '@/routes/supplier/verification';
import { mobile } from '@/routes/supplier/verification';

export default function SupplierVerificationNotice({
    emailVerified,
    mobileVerified,
    status,
}: {
    emailVerified: boolean;
    mobileVerified: boolean;
    status?: string;
}) {
    const { t } = useTranslation();

    const rows = [
        {
            key: 'email',
            label: t('supplier.verification.email'),
            done: emailVerified,
        },
        {
            key: 'mobile',
            label: t('supplier.verification.mobile'),
            done: mobileVerified,
        },
    ];

    return (
        <>
            <Head title={t('supplier.verification.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('supplier.verification.title')}
                    description={t('supplier.verification.description')}
                />

                <SectionCard title={t('supplier.verification.title')}>
                    <ul className="divide-border divide-y">
                        {rows.map((row) => (
                            <li
                                key={row.key}
                                className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                {row.done ? (
                                    <CheckCircle2
                                        className="text-success size-5"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <Circle
                                        className="text-muted-foreground size-5"
                                        aria-hidden="true"
                                    />
                                )}
                                <span className="flex-1 text-sm font-medium">
                                    {row.label}
                                </span>
                                <span className="text-muted-foreground text-sm">
                                    {row.done
                                        ? t('supplier.verification.verified')
                                        : t('supplier.verification.pending')}
                                </span>

                                {row.key === 'email' && !row.done && (
                                    <Form
                                        {...send.form()}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <SubmitButton
                                                processing={processing}
                                                variant="outline"
                                                size="sm"
                                            >
                                                {t(
                                                    'supplier.verification.resend',
                                                )}
                                            </SubmitButton>
                                        )}
                                    </Form>
                                )}

                                {row.key === 'mobile' && !row.done && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={mobile()}>
                                            {t('supplier.verification.verify')}
                                        </Link>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>

                    {status === 'verification-link-sent' && (
                        <p
                            className="mt-4 text-sm font-medium text-green-600"
                            role="status"
                        >
                            {t('supplier.verification.link_sent')}
                        </p>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}
