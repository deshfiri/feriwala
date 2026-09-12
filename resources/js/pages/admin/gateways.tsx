import { Form, Head } from '@inertiajs/react';
import PaymentGatewayController from '@/actions/App/Http/Controllers/Admin/PaymentGatewayController';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';

type GatewayRow = {
    name: string;
    label: string;
    is_implemented: boolean;
    is_operational: boolean;
    is_enabled: boolean;
    is_configured: boolean;
    is_available: boolean;
    is_sandbox: boolean | null;
    capabilities: string[];
    currencies: string[];
    required_configuration: string[];
    missing_configuration: string[];
    last_verified_at: string | null;
};

type Props = {
    gateways: GatewayRow[];
    modes: string[];
    can: { manage: boolean };
};

/**
 * The payment gateways, and what each one still needs (§26.4).
 *
 * All eight are listed rather than only the working ones. "Why can nobody pay by
 * bKash" is answered by seeing it here marked as not built yet — an empty list
 * answers nothing.
 *
 * Each provider states what it can actually do. A gateway with no refund
 * endpoint shows no refund capability, and nothing on this screen offers an
 * action the provider has no way to perform.
 *
 * **No credential is on this page.** The rows say whether one is present and
 * name the ones that are not; the value only ever travels inbound. There is
 * nothing here to read back, which is the point (§42).
 */
export default function Gateways({ gateways, modes, can }: Props) {
    const { t } = useTranslation();

    /*
     * "Built" means the driver can actually do something, not that a class is
     * wired up. EPS and Nagad have both — a place for their credentials and a
     * driver that declares nothing — because their protocols could not be
     * confirmed against official documentation. Listing them as ready would be
     * the screen telling a lie the server already refuses to act on.
     */
    const implemented = gateways.filter((gateway) => gateway.is_operational);
    const planned = gateways.filter((gateway) => !gateway.is_operational);

    const state = (
        gateway: GatewayRow,
    ): { tone: StatusTone; label: string } => {
        if (!gateway.is_operational) {
            return {
                tone: 'neutral',
                label: t('gateways.state.not_implemented'),
            };
        }

        if (!gateway.is_configured) {
            return {
                tone: 'warning',
                label: t('gateways.state.not_configured'),
            };
        }

        if (!gateway.is_enabled) {
            return { tone: 'neutral', label: t('gateways.state.disabled') };
        }

        return { tone: 'success', label: t('gateways.state.available') };
    };

    /** A credential field's name, from the key the driver declared. */
    const fieldLabel = (key: string) => t(`gateways.fields.${key}`);

    return (
        <>
            <Head title={t('gateways.title')} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('gateways.title')}
                    description={t('gateways.description')}
                />

                <SectionCard
                    title={t('gateways.overview.title')}
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

                {implemented.map((gateway) => (
                    <SectionCard
                        key={gateway.name}
                        title={gateway.label}
                        description={t('gateways.credentials.description')}
                        headingLevel="h2"
                        actions={
                            <StatusPill
                                tone={state(gateway).tone}
                                label={state(gateway).label}
                            />
                        }
                    >
                        <dl className="mb-5 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('gateways.mode.label')}
                                </dt>
                                <dd className="font-medium">
                                    {gateway.is_sandbox
                                        ? t('gateways.mode.sandbox')
                                        : t('gateways.mode.live')}
                                </dd>
                            </div>

                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {t('gateways.currencies')}
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {gateway.currencies.join(', ')}
                                </dd>
                            </div>

                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground text-xs">
                                    {t('gateways.capabilities')}
                                </dt>
                                <dd className="mt-1 flex flex-wrap gap-1.5">
                                    {gateway.capabilities.map((capability) => (
                                        <Badge
                                            key={capability}
                                            variant="secondary"
                                        >
                                            {t(
                                                `gateways.capability.${capability}`,
                                            )}
                                        </Badge>
                                    ))}
                                </dd>
                            </div>

                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground text-xs">
                                    {t('gateways.last_verified')}
                                </dt>
                                <dd className="font-medium">
                                    {gateway.last_verified_at ??
                                        t('gateways.never_verified')}
                                </dd>
                            </div>

                            {gateway.missing_configuration.length > 0 && (
                                <div className="sm:col-span-2">
                                    <dt className="text-muted-foreground text-xs">
                                        {t('gateways.missing')}
                                    </dt>
                                    <dd className="text-warning font-medium">
                                        {gateway.missing_configuration
                                            .map(fieldLabel)
                                            .join(', ')}
                                    </dd>
                                </div>
                            )}
                        </dl>

                        {can.manage && (
                            <>
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
                                                value={gateway.name}
                                            />

                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="grid gap-2">
                                                    <Label
                                                        htmlFor={`${gateway.name}-mode`}
                                                    >
                                                        {t(
                                                            'gateways.mode.label',
                                                        )}
                                                    </Label>
                                                    <select
                                                        id={`${gateway.name}-mode`}
                                                        name="mode"
                                                        required
                                                        defaultValue={
                                                            gateway.is_sandbox ===
                                                            false
                                                                ? 'live'
                                                                : 'sandbox'
                                                        }
                                                        className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                                    >
                                                        {modes.map((mode) => (
                                                            <option
                                                                key={mode}
                                                                value={mode}
                                                            >
                                                                {t(
                                                                    `gateways.mode.${mode}`,
                                                                )}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    <p className="text-muted-foreground text-xs">
                                                        {t(
                                                            'gateways.mode.help',
                                                        )}
                                                    </p>
                                                    <InputError
                                                        message={errors.mode}
                                                    />
                                                </div>

                                                {gateway.required_configuration.map(
                                                    (key) => (
                                                        <div
                                                            key={key}
                                                            className="grid gap-2"
                                                        >
                                                            <Label
                                                                htmlFor={`${gateway.name}-${key}`}
                                                            >
                                                                {fieldLabel(
                                                                    key,
                                                                )}
                                                            </Label>
                                                            {/*
                                                                type=password and no autofill: the
                                                                stored value is never sent here, so
                                                                there is nothing for a browser to
                                                                helpfully put back.
                                                            */}
                                                            <Input
                                                                id={`${gateway.name}-${key}`}
                                                                name={`credentials[${key}]`}
                                                                type="password"
                                                                autoComplete="new-password"
                                                                placeholder={t(
                                                                    gateway.missing_configuration.includes(
                                                                        key,
                                                                    )
                                                                        ? 'gateways.credentials.missing'
                                                                        : 'gateways.credentials.set',
                                                                )}
                                                            />
                                                        </div>
                                                    ),
                                                )}
                                            </div>

                                            <p className="text-muted-foreground text-xs">
                                                {t(
                                                    'gateways.credentials.blank_help',
                                                )}
                                            </p>

                                            <InputError
                                                message={errors.gateway}
                                            />

                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {t(
                                                    'gateways.credentials.submit',
                                                )}
                                            </Button>
                                        </>
                                    )}
                                </Form>

                                <Form
                                    {...PaymentGatewayController.toggle.form()}
                                    options={{ preserveScroll: true }}
                                    className="mt-6 border-t pt-4"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="gateway"
                                                value={gateway.name}
                                            />
                                            <input
                                                type="hidden"
                                                name="enabled"
                                                value={
                                                    gateway.is_enabled ? 0 : 1
                                                }
                                            />

                                            <p className="text-muted-foreground mb-3 text-xs">
                                                {t('gateways.enable.help')}
                                            </p>

                                            <InputError
                                                message={errors.enabled}
                                            />

                                            <Button
                                                type="submit"
                                                variant={
                                                    gateway.is_enabled
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                disabled={processing}
                                            >
                                                {t(
                                                    gateway.is_enabled
                                                        ? 'gateways.enable.disable'
                                                        : 'gateways.enable.enable',
                                                )}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </>
                        )}
                    </SectionCard>
                ))}

                {planned.length > 0 && (
                    <SectionCard
                        title={t('gateways.planned.title')}
                        description={t('gateways.planned.description')}
                    >
                        <p className="text-sm">
                            {planned.map((gateway) => gateway.label).join(', ')}
                        </p>
                    </SectionCard>
                )}
            </PageContainer>
        </>
    );
}
