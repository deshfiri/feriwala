import { Form, Head } from '@inertiajs/react';
import AuditRetentionSettingsController from '@/actions/App/Http/Controllers/Admin/AuditRetentionSettingsController';
import InputError from '@/components/input-error';
import SubmitButton from '@/components/forms/submit-button';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    settings: { days: number };
    maximum: number;
    can: { manage: boolean };
};

/**
 * How many days the activity log is kept (D26). Zero keeps it without limit.
 */
export default function AuditRetentionSettings({
    settings,
    maximum,
    can,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('audit.retention.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('audit.retention.title')}
                    description={t('audit.retention.description')}
                />

                <SectionCard
                    title={t('audit.retention.section')}
                    description={t('audit.retention.section_help')}
                >
                    {can.manage ? (
                        <Form
                            {...AuditRetentionSettingsController.update.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid max-w-48 gap-2">
                                        <Label htmlFor="retention-days">
                                            {t('audit.retention.days')}
                                        </Label>
                                        <Input
                                            id="retention-days"
                                            name="days"
                                            type="number"
                                            inputMode="numeric"
                                            min={0}
                                            max={maximum}
                                            step={1}
                                            defaultValue={settings.days}
                                            required
                                            aria-invalid={
                                                errors.days ? true : undefined
                                            }
                                        />
                                        <InputError message={errors.days} />
                                    </div>

                                    <p className="text-warning text-sm">
                                        {t('audit.retention.warning')}
                                    </p>

                                    <SubmitButton processing={processing}>
                                        {t('common.actions.save')}
                                    </SubmitButton>
                                </>
                            )}
                        </Form>
                    ) : (
                        <div className="space-y-2 text-sm">
                            <p className="font-medium">
                                {settings.days === 0
                                    ? t('audit.retention_off')
                                    : t('audit.retention_note', {
                                          days: settings.days,
                                      })}
                            </p>
                            <p className="text-muted-foreground">
                                {t('audit.retention.read_only')}
                            </p>
                        </div>
                    )}
                </SectionCard>
            </PageContainer>
        </>
    );
}
