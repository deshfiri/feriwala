import { Form, Head } from '@inertiajs/react';
import ProductDeletionSettingsController from '@/actions/App/Http/Controllers/Admin/ProductDeletionSettingsController';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    settings: { scope: string };
    scopes: string[];
    can: { manage: boolean };
};

/**
 * Which product statuses may be deleted: every status, or drafts only.
 */
export default function ProductDeletionSettingsIndex({
    settings,
    scopes,
    can,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('product_deletion_settings.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('product_deletion_settings.title')}
                    description={t('product_deletion_settings.description')}
                />

                <SectionCard
                    title={t('product_deletion_settings.scope.title')}
                    description={t(
                        'product_deletion_settings.scope.description',
                    )}
                >
                    {can.manage ? (
                        <Form
                            {...ProductDeletionSettingsController.update.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ processing }) => (
                                <>
                                    <div
                                        role="radiogroup"
                                        className="space-y-3"
                                    >
                                        {scopes.map((scope) => (
                                            <div
                                                key={scope}
                                                className="flex items-start gap-3"
                                            >
                                                <input
                                                    id={`deletion-scope-${scope}`}
                                                    type="radio"
                                                    name="scope"
                                                    value={scope}
                                                    defaultChecked={
                                                        settings.scope === scope
                                                    }
                                                    className="accent-brand mt-1 size-4"
                                                />
                                                <div>
                                                    <Label
                                                        htmlFor={`deletion-scope-${scope}`}
                                                    >
                                                        {t(
                                                            `product_deletion_settings.scope.${scope}`,
                                                        )}
                                                    </Label>
                                                    <p className="text-muted-foreground text-sm">
                                                        {t(
                                                            `product_deletion_settings.scope.${scope}_help`,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>

                                    <SubmitButton processing={processing}>
                                        {t('common.actions.save')}
                                    </SubmitButton>
                                </>
                            )}
                        </Form>
                    ) : (
                        <div className="space-y-2 text-sm">
                            <p className="font-medium">
                                {t(
                                    `product_deletion_settings.scope.${settings.scope}`,
                                )}
                            </p>
                            <p className="text-muted-foreground">
                                {t('product_deletion_settings.read_only')}
                            </p>
                        </div>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}
