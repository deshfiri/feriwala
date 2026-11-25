import { router, useForm } from '@inertiajs/react';
import { Download, Printer } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import FormField from '@/components/forms/form-field';
import SubmitButton from '@/components/forms/submit-button';
import TextArea from '@/components/forms/text-area';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import type { StatusTone } from '@/lib/status';

export type ShippingLabelSize = '3x3' | '3x4';

export type StaffShippingLabel = {
    shipment: string;
    reference: string;
    status: 'not_available' | 'needs_information' | 'ready' | 'corrected';
    status_label: string;
    status_tone: StatusTone;
    message: string | null;
    version: number | null;
    issued_at: string | null;
    print_url: string;
    issue_url: string;
    reissue_url: string;
    can: { print: boolean; issue: boolean; reissue: boolean };
    versions: Array<{
        version: number;
        issued_at: string;
        issued_by: string | null;
        issue_reason: string | null;
        superseded_at: string | null;
        superseded_reason: string | null;
        print_url: string;
    }>;
};

export type PartnerShippingLabel = {
    shipment: string;
    reference: string;
    ready: boolean;
    parcel: string | null;
    print_url: string | null;
};

const SIZE_KEY = 'banij.shipping-label-size';

/**
 * The label size this browser last printed. Remembered here only: printing a
 * different size never changes the shipment.
 */
export function useShippingLabelSize(): [
    ShippingLabelSize,
    (size: ShippingLabelSize) => void,
] {
    const [size, setSize] = useState<ShippingLabelSize>(() => {
        try {
            return window.localStorage.getItem(SIZE_KEY) === '3x3'
                ? '3x3'
                : '3x4';
        } catch {
            return '3x4';
        }
    });

    const choose = (next: ShippingLabelSize) => {
        setSize(next);

        try {
            window.localStorage.setItem(SIZE_KEY, next);
        } catch {
            // Private mode: the choice lasts for this page only.
        }
    };

    return [size, choose];
}

/**
 * The print page for a size, opened in its own tab. `autoprint` opens the
 * browser's print dialog once the fonts are in, where "Save as PDF" is the
 * download.
 */
export function shippingLabelUrl(
    base: string,
    size: ShippingLabelSize,
    autoprint = true,
): string {
    const url = new URL(base, window.location.origin);

    url.searchParams.set('size', size);

    if (autoprint) {
        url.searchParams.set('autoprint', '1');
    }

    return url.toString();
}

export function ShippingLabelSizePicker({
    size,
    onChange,
}: {
    size: ShippingLabelSize;
    onChange: (size: ShippingLabelSize) => void;
}) {
    const { t } = useTranslation();

    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={size}
            onValueChange={(value) =>
                value && onChange(value as ShippingLabelSize)
            }
            aria-label={t('shipping_label.panel.size')}
        >
            <ToggleGroupItem value="3x3">3 × 3</ToggleGroupItem>
            <ToggleGroupItem value="3x4">3 × 4</ToggleGroupItem>
        </ToggleGroup>
    );
}

/**
 * Print and "Download PDF" for one label. Both open the same print page, so
 * the PDF can only ever show what the printed label shows.
 */
export function ShippingLabelPrintButtons({
    url,
    size,
}: {
    url: string;
    size: ShippingLabelSize;
}) {
    const { t } = useTranslation();
    const target = shippingLabelUrl(url, size);

    return (
        <div className="flex flex-wrap gap-2">
            <Button asChild size="sm">
                <a href={target} target="_blank" rel="noopener noreferrer">
                    <Printer aria-hidden="true" />
                    {t('shipping_label.panel.print_size', {
                        size: size === '3x3' ? '3 × 3' : '3 × 4',
                    })}
                </a>
            </Button>
            <Button asChild size="sm" variant="outline">
                <a
                    href={target}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={t('shipping_label.panel.pdf_hint')}
                >
                    <Download aria-hidden="true" />
                    {t('shipping_label.panel.download_pdf')}
                </a>
            </Button>
        </div>
    );
}

/**
 * Staff: the label's state, why it is waiting, printing, and -- for shipment
 * managers -- issuing, retrying and a reasoned correction.
 */
export function StaffShippingLabelPanel({
    label,
    compact = false,
}: {
    label: StaffShippingLabel;
    compact?: boolean;
}) {
    const { t, locale } = useTranslation();
    const [size, setSize] = useShippingLabelSize();
    const [correcting, setCorrecting] = useState(false);
    const [issuing, setIssuing] = useState(false);
    const form = useForm({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const issue = () => {
        setIssuing(true);
        router.post(
            label.issue_url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setIssuing(false),
            },
        );
    };

    const reissue = (event: FormEvent) => {
        event.preventDefault();
        form.post(label.reissue_url, {
            preserveScroll: true,
            onSuccess: () => {
                setCorrecting(false);
                form.reset();
            },
        });
    };

    const date = (value: string | null) =>
        value ? new Date(value).toLocaleString(locale) : '—';

    const body = (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <StatusPill
                    tone={label.status_tone}
                    label={label.status_label}
                />
                {label.version !== null && (
                    <span className="text-muted-foreground text-xs">
                        {t('shipping_label.panel.version', {
                            version: label.version,
                        })}
                        {' · '}
                        {t('shipping_label.panel.issued', {
                            date: date(label.issued_at),
                        })}
                    </span>
                )}
            </div>

            {label.message && (
                <p className="text-warning text-sm" role="status">
                    {label.message}
                </p>
            )}

            {label.can.print && (
                <div className="flex flex-wrap items-center gap-3">
                    <ShippingLabelSizePicker size={size} onChange={setSize} />
                    <ShippingLabelPrintButtons
                        url={label.print_url}
                        size={size}
                    />
                </div>
            )}

            {(label.can.issue || label.can.reissue) && (
                <div className="flex flex-wrap gap-2">
                    {label.can.issue && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={issuing}
                            onClick={issue}
                        >
                            {t(
                                label.status === 'needs_information'
                                    ? 'shipping_label.panel.retry'
                                    : 'shipping_label.panel.issue',
                            )}
                        </Button>
                    )}
                    {label.can.reissue && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setCorrecting(true)}
                        >
                            {t('shipping_label.panel.reissue')}
                        </Button>
                    )}
                </div>
            )}

            {errors.label && (
                <p className="text-destructive text-sm" role="alert">
                    {errors.label}
                </p>
            )}

            {label.versions.length > 1 && (
                <div>
                    <h3 className="text-sm font-medium">
                        {t('shipping_label.panel.versions')}
                    </h3>
                    <ol className="border-border mt-2 space-y-2 border-l pl-3 text-sm">
                        {label.versions.map((version) => (
                            <li key={version.version}>
                                <a
                                    href={shippingLabelUrl(
                                        version.print_url,
                                        size,
                                        false,
                                    )}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {t('shipping_label.panel.version', {
                                        version: version.version,
                                    })}
                                </a>
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {date(version.issued_at)}
                                    {version.issued_by &&
                                        ` · ${version.issued_by}`}
                                </span>
                                {version.issue_reason && (
                                    <p className="text-muted-foreground">
                                        {version.issue_reason}
                                    </p>
                                )}
                                {version.superseded_at && (
                                    <p className="text-muted-foreground">
                                        {t('shipping_label.panel.replaced', {
                                            date: date(version.superseded_at),
                                            reason:
                                                version.superseded_reason ?? '',
                                        })}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                </div>
            )}

            <Dialog open={correcting} onOpenChange={setCorrecting}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('shipping_label.panel.reissue')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('shipping_label.panel.reissue_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={reissue} className="space-y-4">
                        {errors.label && (
                            <p
                                className="text-destructive text-sm"
                                role="alert"
                            >
                                {errors.label}
                            </p>
                        )}
                        <FormField
                            label={t('shipping_label.panel.reason')}
                            error={form.errors.reason}
                            required
                        >
                            {(field) => (
                                <TextArea
                                    {...field}
                                    rows={3}
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                    required
                                />
                            )}
                        </FormField>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setCorrecting(false)}
                            >
                                {t('common.actions.cancel')}
                            </Button>
                            <SubmitButton processing={form.processing}>
                                {t('shipping_label.panel.reissue')}
                            </SubmitButton>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );

    if (compact) {
        return body;
    }

    return (
        <SectionCard title={t('shipping_label.panel.title')}>
            {body}
        </SectionCard>
    );
}

/**
 * Partner: the labels of their own order's parcels, ready ones printable.
 * Nothing about why one is not ready beyond "waiting for the courier".
 */
export function PartnerShippingLabels({
    labels,
}: {
    labels: PartnerShippingLabel[];
}) {
    const { t } = useTranslation();
    const [size, setSize] = useShippingLabelSize();

    if (labels.length === 0) {
        return null;
    }

    const anyReady = labels.some((label) => label.ready);

    return (
        <SectionCard title={t('shipping_label.panel.title')}>
            <div className="space-y-3">
                {anyReady && (
                    <ShippingLabelSizePicker size={size} onChange={setSize} />
                )}
                <ul className="divide-border divide-y">
                    {labels.map((label) => (
                        <li
                            key={label.shipment}
                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <div className="min-w-0">
                                <span className="font-mono text-sm font-medium">
                                    {label.reference}
                                </span>
                                {label.parcel && (
                                    <span className="text-muted-foreground text-xs">
                                        {' · '}
                                        {label.parcel}
                                    </span>
                                )}
                            </div>
                            {label.ready && label.print_url ? (
                                <ShippingLabelPrintButtons
                                    url={label.print_url}
                                    size={size}
                                />
                            ) : (
                                <span className="text-muted-foreground text-sm">
                                    {t('shipping_label.panel.partner_waiting')}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </SectionCard>
    );
}
