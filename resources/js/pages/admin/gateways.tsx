import { Form, Head } from '@inertiajs/react';
import PaymentGatewayController from '@/actions/App/Http/Controllers/Admin/PaymentGatewayController';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type GatewayRow = {
    name: string;
    label: string;
    is_implemented: boolean;
    is_enabled: boolean;
    is_configured: boolean;
    is_available: boolean;
    is_sandbox: boolean | null;
};

type Props = {
    gateways: GatewayRow[];
    can: { manage: boolean };
};

/**
 * The payment gateways, and what each one still needs (§26.4).
 *
 * All eight are listed rather than only the working one. "Why can nobody pay by
 * bKash" is answered by seeing it here marked as not built yet — an empty list
 * answers nothing.
 *
 * **No credential is on this page.** The rows say whether one is present; the
 * value only ever travels inbound. There is nothing here to read back, which is
 * the point (§42).
 */
export default function Gateways({ gateways, can }: Props) {
    const { t } = useTranslation();

    const sslcommerz = gateways.find(
        (gateway) => gateway.name === 'sslcommerz',
    );

    const state = (gateway: GatewayRow) => {
        if (!gateway.is_implemented) {
            return {
                tone: 'neutral' as const,
                label: t('gateways.state.not_implemented'),
            };
        }

        if (!gateway.is_enabled) {
            return {
                tone: 'neutral' as const,
                label: t('gateways.state.disabled'),
            };
        }

        if (!gateway.is_configured) {
            return {
                tone: 'warning' as const,
                label: t('gateways.state.not_configured'),
            };
        }

        return {
            tone: 'success' as const,
            label: t('gateways.state.available'),
        };
    };

    return (
        <>
            <Head title={t('gateways.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('gateways.title')}
                    description={t('gateways.description')}
                />

                <SectionCard
                    title={t('gateways.title')}
                    description={t('gateways.empty_help')}
                    contentClassName="p-0"
                >
                    <ul className="divide-border divide-y text-sm">
                        {gateways.map((gateway) => (
                            <li
                                key={gateway.name}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {gateway.label}
                                    </p>
                                    {gateway.is_sandbox === true && (
                                        <p className="text-muted-foreground text-xs">
                                            {t('gateways.sandbox_notice')}
                                        </p>
                                    )}
                                </div>

                                {/* Label carries the state, never colour alone (§33.9). */}
                                <StatusPill
                                    tone={state(gateway).tone}
                                    label={state(gateway).label}
                                />
                            </li>
                        ))}
                    </ul>
                </SectionCard>

                {can.manage && sslcommerz !== undefined && (
                    <SectionCard
                        title={t('gateways.credentials.title')}
                        description={t('gateways.credentials.description')}
                    >
                        <Form
                            {...PaymentGatewayController.update.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="gateway"
                                        value="sslcommerz"
                                    />

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="gateway-mode">
                                                {t('gateways.mode.label')}
                                            </Label>
                                            <select
                                                id="gateway-mode"
                                                name="mode"
                                                required
                                                defaultValue={
                                                    sslcommerz.is_sandbox ===
                                                    false
                                                        ? 'live'
                                                        : 'sandbox'
                                                }
                                                className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                <option value="sandbox">
                                                    {t('gateways.mode.sandbox')}
                                                </option>
                                                <option value="live">
                                                    {t('gateways.mode.live')}
                                                </option>
                                            </select>
                                            <p className="text-muted-foreground text-xs">
                                                {t('gateways.mode.help')}
                                            </p>
                                            <InputError message={errors.mode} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="gateway-store-id">
                                                {t(
                                                    'gateways.credentials.store_id',
                                                )}
                                            </Label>
                                            <Input
                                                id="gateway-store-id"
                                                name="store_id"
                                                autoComplete="off"
                                                placeholder={t(
                                                    sslcommerz.is_configured
                                                        ? 'gateways.credentials.set'
                                                        : 'gateways.credentials.missing',
                                                )}
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'gateways.credentials.blank_help',
                                                )}
                                            </p>
                                            <InputError
                                                message={errors.store_id}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="gateway-store-password">
                                                {t(
                                                    'gateways.credentials.store_password',
                                                )}
                                            </Label>
                                            {/*
                                                type=password and no autofill: the
                                                stored value is never sent here, so
                                                there is nothing for a browser to
                                                helpfully put back.
                                            */}
                                            <Input
                                                id="gateway-store-password"
                                                name="store_password"
                                                type="password"
                                                autoComplete="new-password"
                                                placeholder={t(
                                                    sslcommerz.is_configured
                                                        ? 'gateways.credentials.set'
                                                        : 'gateways.credentials.missing',
                                                )}
                                            />
                                            <InputError
                                                message={errors.store_password}
                                            />
                                        </div>
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        {t('gateways.credentials.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}
