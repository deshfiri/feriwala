import { Form, Head } from '@inertiajs/react';
import SmsController from '@/actions/App/Http/Controllers/Admin/SmsController';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type ProviderRow = {
    name: string;
    is_implemented: boolean;
    is_active: boolean;
};

type Props = {
    settings: { enabled: boolean; provider: string; can_send: boolean };
    providers: ProviderRow[];
    can: { manage: boolean };
};

/**
 * SMS settings (§30).
 *
 * The global switch and the provider are one form, because they are one
 * question: can a message go out right now, and through whom. Every provider
 * §30.1 names is listed including the unbuilt ones — an absence explains
 * nothing, a row saying "not built yet" explains everything.
 */
export default function AdminSms({ settings, providers, can }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('sms.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('sms.title')}
                    description={t('sms.description')}
                />

                <SectionCard
                    title={t('sms.switch.title')}
                    description={t('sms.switch.description')}
                >
                    {!settings.enabled && (
                        <p className="text-muted-foreground mb-4 text-sm">
                            {t('sms.switch.disabled_notice')}
                        </p>
                    )}

                    {settings.enabled && !settings.can_send && (
                        <p className="text-danger mb-4 text-sm font-medium">
                            {t('sms.switch.unavailable_notice')}
                        </p>
                    )}

                    {can.manage ? (
                        <Form
                            {...SmsController.update.form()}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="flex items-center gap-3">
                                        {/* A hidden false ahead of the checkbox:
                                            an unchecked box sends nothing, and
                                            "nothing" is not "off". */}
                                        <input
                                            type="hidden"
                                            name="enabled"
                                            value="0"
                                        />
                                        <input
                                            id="sms-enabled"
                                            type="checkbox"
                                            name="enabled"
                                            value="1"
                                            defaultChecked={settings.enabled}
                                            className="accent-brand size-4"
                                        />
                                        <Label htmlFor="sms-enabled">
                                            {t('sms.switch.enabled')}
                                        </Label>
                                    </div>

                                    <div className="grid max-w-sm gap-2">
                                        <Label htmlFor="sms-provider">
                                            {t('sms.providers.choose')}
                                        </Label>
                                        <select
                                            id="sms-provider"
                                            name="provider"
                                            defaultValue={settings.provider}
                                            className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                        >
                                            {providers
                                                .filter(
                                                    (provider) =>
                                                        provider.is_implemented,
                                                )
                                                .map((provider) => (
                                                    <option
                                                        key={provider.name}
                                                        value={provider.name}
                                                    >
                                                        {provider.name}
                                                    </option>
                                                ))}
                                        </select>
                                        <InputError message={errors.provider} />
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        {t('sms.providers.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm">
                            {settings.enabled
                                ? t('sms.switch.enabled')
                                : t('sms.switch.disabled_notice')}
                        </p>
                    )}
                </SectionCard>

                <SectionCard
                    title={t('sms.providers.title')}
                    description={t('sms.providers.description')}
                    contentClassName="p-0"
                >
                    <ul className="divide-border divide-y text-sm">
                        {providers.map((provider) => (
                            <li
                                key={provider.name}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                            >
                                <span className="font-medium">
                                    {provider.name}
                                </span>

                                {/* Labelled, never colour alone (§33.9). */}
                                <StatusPill
                                    tone={
                                        provider.is_active
                                            ? 'success'
                                            : provider.is_implemented
                                              ? 'info'
                                              : 'neutral'
                                    }
                                    label={t(
                                        provider.is_active
                                            ? 'sms.providers.active'
                                            : provider.is_implemented
                                              ? 'sms.providers.available'
                                              : 'sms.providers.not_implemented',
                                    )}
                                />
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </PageContainer>
        </>
    );
}
