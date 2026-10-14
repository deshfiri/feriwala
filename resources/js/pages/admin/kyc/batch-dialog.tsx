import { Form } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import KycBatchController from '@/actions/App/Http/Controllers/Admin/KycBatchController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { KycBatchRow, KycBatchRule, SelectOption } from '@/types';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null creates; a row edits it. */
    batch: KycBatchRow | null;
    packages: SelectOption[];
    countries: SelectOption[];
};

/**
 * Create or edit one verification batch (§7.2).
 *
 * A batch is a group of criteria an applicant completes together — "Identity"
 * holding the NID, the passport and the selfie. What it holds is added from the
 * criteria list; this dialog is the batch itself and who it applies to.
 *
 * The rules are edited as a list in the same form, and the server replaces them
 * wholesale on save, so what is on screen is what will exist. No rules means
 * everyone; with rules, whoever matches any one of them.
 */
export default function BatchDialog({
    open,
    onOpenChange,
    batch,
    packages,
    countries,
}: Props) {
    const { t } = useTranslation();
    const [rules, setRules] = useState<KycBatchRule[]>([]);
    const [isActive, setIsActive] = useState(true);

    // Re-seeded whenever the dialog opens on a different row, so editing one
    // batch never shows another one's rules.
    useEffect(() => {
        if (!open) {
            return;
        }

        setRules(batch?.rules ?? []);
        setIsActive(batch?.is_active ?? true);
    }, [open, batch]);

    const submit = batch
        ? KycBatchController.update.form(batch.id)
        : KycBatchController.store.form();

    const update = (index: number, patch: Partial<KycBatchRule>) =>
        setRules((current) =>
            current.map((rule, i) =>
                i === index ? { ...rule, ...patch } : rule,
            ),
        );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {batch
                            ? t('kyc.batches.form.edit_title')
                            : t('kyc.batches.form.create_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('kyc.batches.form.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...submit}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="batch-name">
                                    {t('kyc.batches.form.name')}
                                </Label>
                                <Input
                                    id="batch-name"
                                    name="name"
                                    defaultValue={batch?.name ?? ''}
                                    required
                                    placeholder={t(
                                        'kyc.batches.form.name_placeholder',
                                    )}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="batch-key">
                                    {t('kyc.batches.form.key')}
                                </Label>
                                <Input
                                    id="batch-key"
                                    name="key"
                                    defaultValue={batch?.key ?? ''}
                                    required
                                    autoComplete="off"
                                    placeholder="identity"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('kyc.batches.form.key_help')}
                                </p>
                                <InputError message={errors.key} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="batch-description">
                                    {t('kyc.batches.form.description_label')}
                                </Label>
                                <textarea
                                    id="batch-description"
                                    name="description"
                                    rows={2}
                                    defaultValue={batch?.description ?? ''}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('kyc.batches.form.description_help')}
                                </p>
                                <InputError message={errors.description} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="batch-audience">
                                    {t('kyc.batches.form.audience')}
                                </Label>
                                <select
                                    id="batch-audience"
                                    name="audience"
                                    defaultValue={batch?.audience ?? 'account'}
                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                >
                                    {(
                                        ['account', 'supplier', 'both'] as const
                                    ).map((audience) => (
                                        <option key={audience} value={audience}>
                                            {t(
                                                `kyc.document_types.audience.${audience}`,
                                            )}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-muted-foreground text-xs">
                                    {t('kyc.batches.form.audience_help')}
                                </p>
                                <InputError message={errors.audience} />
                            </div>

                            <div className="flex items-center gap-2">
                                {/* A boolean must post something when unticked. */}
                                <input
                                    type="hidden"
                                    name="is_active"
                                    value={isActive ? '1' : '0'}
                                />
                                <Checkbox
                                    id="batch-active"
                                    checked={isActive}
                                    onCheckedChange={(next) =>
                                        setIsActive(next === true)
                                    }
                                />
                                <Label
                                    htmlFor="batch-active"
                                    className="font-normal"
                                >
                                    {t('kyc.batches.form.is_active')}
                                </Label>
                            </div>

                            <fieldset className="space-y-3 rounded-lg border p-3">
                                <legend className="px-1 text-sm font-medium">
                                    {t('kyc.batches.rules.heading')}
                                </legend>

                                <p className="text-muted-foreground text-xs">
                                    {t('kyc.batches.rules.help')}
                                </p>

                                {rules.length === 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        {t('kyc.batches.rules.everyone')}
                                    </p>
                                )}

                                {rules.map((rule, index) => (
                                    <div
                                        key={index}
                                        className="space-y-2 rounded-md border p-2"
                                    >
                                        <input
                                            type="hidden"
                                            name={`rules[${index}][package]`}
                                            value={rule.package ?? ''}
                                        />
                                        <input
                                            type="hidden"
                                            name={`rules[${index}][country]`}
                                            value={rule.country ?? ''}
                                        />

                                        <div className="grid gap-2 sm:grid-cols-2">
                                            <div className="grid gap-1">
                                                <Label
                                                    htmlFor={`rule-package-${index}`}
                                                    className="text-xs"
                                                >
                                                    {t(
                                                        'kyc.document_types.scopes.package',
                                                    )}
                                                </Label>
                                                <select
                                                    id={`rule-package-${index}`}
                                                    value={rule.package ?? ''}
                                                    onChange={(event) =>
                                                        update(index, {
                                                            package:
                                                                event.target
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                                >
                                                    <option value="">
                                                        {t(
                                                            'kyc.batches.rules.any_package',
                                                        )}
                                                    </option>
                                                    {packages.map((option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>

                                            <div className="grid gap-1">
                                                <Label
                                                    htmlFor={`rule-country-${index}`}
                                                    className="text-xs"
                                                >
                                                    {t(
                                                        'kyc.document_types.scopes.country',
                                                    )}
                                                </Label>
                                                {/* Chosen, never typed: "Bangladsh"
                                                    looks configured and matches
                                                    nobody. */}
                                                <select
                                                    id={`rule-country-${index}`}
                                                    value={rule.country ?? ''}
                                                    onChange={(event) =>
                                                        update(index, {
                                                            country:
                                                                event.target
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                                >
                                                    <option value="">
                                                        {t(
                                                            'kyc.batches.rules.any_country',
                                                        )}
                                                    </option>
                                                    {countries.map((option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>

                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setRules((current) =>
                                                        current.filter(
                                                            (_, i) =>
                                                                i !== index,
                                                        ),
                                                    )
                                                }
                                            >
                                                <X className="size-4" />
                                                {t(
                                                    'kyc.document_types.scopes.remove',
                                                )}
                                            </Button>
                                        </div>

                                        <InputError
                                            message={
                                                errors[`rules.${index}.package`]
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[`rules.${index}.country`]
                                            }
                                        />
                                    </div>
                                ))}

                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setRules((current) => [
                                            ...current,
                                            { package: null, country: null },
                                        ])
                                    }
                                >
                                    <Plus className="size-4" />
                                    {t('kyc.batches.rules.add')}
                                </Button>
                            </fieldset>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('kyc.document_types.form.cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('kyc.batches.form.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
