import { Form } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import KycDocumentTypeController from '@/actions/App/Http/Controllers/Admin/KycDocumentTypeController';
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
import type {
    KycDocumentTypeRow,
    KycDocumentTypeScopeRow,
    SelectOption,
} from '@/types';

/** What a new requirement starts with — the formats a phone camera produces. */
const DEFAULT_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null creates; a row edits it. */
    type: KycDocumentTypeRow | null;
    /** Packages a rule may name. Empty until P1-32 builds Package CRUD. */
    packages: SelectOption[];
    /** The countries Feriwala serves (config/countries.php). */
    countries: SelectOption[];
};

/**
 * Create or edit one KYC requirement (§7.2).
 *
 * Scope rules are edited as a list in the same form rather than on a screen of
 * their own. Who a requirement applies to is part of what the requirement *is*,
 * and splitting them means an administrator can save a rule that contradicts
 * the type they have not saved yet.
 *
 * The server replaces the rules wholesale on save, so what is on screen is what
 * will exist — there is no merge to reason about.
 */
export default function RequirementDialog({
    open,
    onOpenChange,
    type,
    packages,
    countries,
}: Props) {
    const { t } = useTranslation();
    const [scopes, setScopes] = useState<KycDocumentTypeScopeRow[]>([]);
    const [requiresFile, setRequiresFile] = useState(true);
    const [requiresValue, setRequiresValue] = useState(false);
    const [mimeText, setMimeText] = useState(DEFAULT_MIME_TYPES.join('\n'));

    const mimeTypes = mimeText
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

    // Re-seeded whenever the dialog opens on a different row, so editing one
    // requirement never shows another one's rules.
    useEffect(() => {
        if (!open) {
            return;
        }

        setScopes(type?.scopes ?? []);
        setRequiresFile(type?.requires_file ?? true);
        setRequiresValue(type?.requires_value ?? false);
        setMimeText(
            (type?.accepted_mime_types ?? DEFAULT_MIME_TYPES).join('\n'),
        );
    }, [open, type]);

    const submit = type
        ? KycDocumentTypeController.update.form(type.id)
        : KycDocumentTypeController.store.form();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {type
                            ? t('kyc.document_types.form.edit_title')
                            : t('kyc.document_types.form.create_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('kyc.document_types.form.description')}
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
                                <Label htmlFor="key">
                                    {t('kyc.document_types.form.key')}
                                </Label>
                                <Input
                                    id="key"
                                    name="key"
                                    defaultValue={type?.key ?? ''}
                                    required
                                    autoComplete="off"
                                    placeholder="national_id"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('kyc.document_types.form.key_help')}
                                </p>
                                <InputError message={errors.key} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">
                                    {t('kyc.document_types.form.name')}
                                </Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={type?.name ?? ''}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="instructions">
                                    {t('kyc.document_types.form.instructions')}
                                </Label>
                                <textarea
                                    id="instructions"
                                    name="instructions"
                                    rows={2}
                                    defaultValue={type?.instructions ?? ''}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t(
                                        'kyc.document_types.form.instructions_help',
                                    )}
                                </p>
                                <InputError message={errors.instructions} />
                            </div>

                            <fieldset className="grid gap-2">
                                <CheckboxField
                                    name="is_required"
                                    label={t(
                                        'kyc.document_types.form.is_required',
                                    )}
                                    defaultChecked={type?.is_required ?? true}
                                />
                                <CheckboxField
                                    name="is_active"
                                    label={t(
                                        'kyc.document_types.form.is_active',
                                    )}
                                    defaultChecked={type?.is_active ?? true}
                                />
                                <CheckboxField
                                    name="requires_file"
                                    label={t(
                                        'kyc.document_types.form.requires_file',
                                    )}
                                    checked={requiresFile}
                                    onCheckedChange={setRequiresFile}
                                />
                                <CheckboxField
                                    name="requires_value"
                                    label={t(
                                        'kyc.document_types.form.requires_value',
                                    )}
                                    checked={requiresValue}
                                    onCheckedChange={setRequiresValue}
                                />
                                <InputError message={errors.requires_file} />
                            </fieldset>

                            {requiresValue && (
                                <div className="grid gap-2">
                                    <Label htmlFor="value_label">
                                        {t(
                                            'kyc.document_types.form.value_label',
                                        )}
                                    </Label>
                                    <Input
                                        id="value_label"
                                        name="value_label"
                                        defaultValue={type?.value_label ?? ''}
                                    />
                                    <InputError message={errors.value_label} />
                                </div>
                            )}

                            {requiresFile && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="accepted_mime_types">
                                            {t(
                                                'kyc.document_types.form.accepted_mime_types',
                                            )}
                                        </Label>

                                        {/*
                                         * Edited as lines, posted as an array.
                                         * The hidden inputs are rendered from
                                         * state rather than written into the
                                         * DOM, so what posts is always what the
                                         * textarea currently shows.
                                         */}
                                        {mimeTypes.map((mime, index) => (
                                            <input
                                                key={index}
                                                type="hidden"
                                                name="accepted_mime_types[]"
                                                value={mime}
                                            />
                                        ))}

                                        <textarea
                                            id="accepted_mime_types"
                                            rows={3}
                                            value={mimeText}
                                            onChange={(event) =>
                                                setMimeText(event.target.value)
                                            }
                                            className="border-input bg-background rounded-md border px-3 py-2 font-mono text-sm"
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            {t(
                                                'kyc.document_types.form.accepted_help',
                                            )}
                                        </p>
                                        <InputError
                                            message={
                                                errors.accepted_mime_types ??
                                                errors['accepted_mime_types.0']
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="max_size_kb">
                                            {t(
                                                'kyc.document_types.form.max_size_kb',
                                            )}
                                        </Label>
                                        <Input
                                            id="max_size_kb"
                                            name="max_size_kb"
                                            type="number"
                                            min={1}
                                            defaultValue={
                                                type?.max_size_kb ?? 5120
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.max_size_kb}
                                        />
                                    </div>
                                </>
                            )}

                            <ScopeEditor
                                scopes={scopes}
                                onChange={setScopes}
                                errors={errors}
                                packages={packages}
                                countries={countries}
                            />

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
                                    {t('kyc.document_types.form.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function CheckboxField({
    name,
    label,
    defaultChecked,
    checked,
    onCheckedChange,
}: {
    name: string;
    label: string;
    defaultChecked?: boolean;
    checked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
}) {
    const [internal, setInternal] = useState(defaultChecked ?? false);
    const isControlled = checked !== undefined;
    const value = isControlled ? checked : internal;

    return (
        <div className="flex items-center gap-2">
            {/* A boolean must post something when unticked, or the required
                rule fails on an intentional "no". */}
            <input type="hidden" name={name} value={value ? '1' : '0'} />
            <Checkbox
                id={name}
                checked={value}
                onCheckedChange={(next) => {
                    const state = next === true;

                    if (isControlled) {
                        onCheckedChange?.(state);
                    } else {
                        setInternal(state);
                    }
                }}
            />
            <Label htmlFor={name} className="font-normal">
                {label}
            </Label>
        </div>
    );
}

function ScopeEditor({
    scopes,
    onChange,
    errors,
    packages,
    countries,
}: {
    scopes: KycDocumentTypeScopeRow[];
    onChange: (scopes: KycDocumentTypeScopeRow[]) => void;
    errors: Record<string, string | undefined>;
    packages: SelectOption[];
    countries: SelectOption[];
}) {
    const { t } = useTranslation();

    /*
     * With no packages configured there is nothing to choose between, so the
     * control is absent rather than an empty dropdown or a box to guess a slug
     * into. A rule naming a package that does not exist matches nobody, and
     * nothing tells anyone until an applicant is asked for the wrong documents.
     */
    const canScopeByPackage = packages.length > 0;

    const update = (index: number, patch: Partial<KycDocumentTypeScopeRow>) => {
        onChange(
            scopes.map((scope, i) =>
                i === index ? { ...scope, ...patch } : scope,
            ),
        );
    };

    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">
                {t('kyc.document_types.scopes.heading')}
            </legend>

            <p className="text-muted-foreground text-xs">
                {t('kyc.document_types.scopes.help')}
            </p>

            {scopes.length === 0 && (
                <p className="text-muted-foreground text-sm">
                    {t('kyc.document_types.scopes.everyone')}
                </p>
            )}

            {scopes.map((scope, index) => (
                <div key={index} className="space-y-2 rounded-md border p-2">
                    <input
                        type="hidden"
                        name={`scopes[${index}][package]`}
                        value={scope.package ?? ''}
                    />
                    <input
                        type="hidden"
                        name={`scopes[${index}][country]`}
                        value={scope.country ?? ''}
                    />
                    <input
                        type="hidden"
                        name={`scopes[${index}][is_required]`}
                        value={
                            scope.is_required === null
                                ? ''
                                : scope.is_required
                                  ? '1'
                                  : '0'
                        }
                    />

                    <div className="grid gap-2 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`scope-package-${index}`}
                                className="text-xs"
                            >
                                {t('kyc.document_types.scopes.package')}
                            </Label>

                            {canScopeByPackage ? (
                                <>
                                    {/*
                                     * Searchable by typing, and still a closed
                                     * set: the value posted is a slug the
                                     * server re-validates, so narrowing the
                                     * list cannot widen what is accepted.
                                     */}
                                    <Input
                                        id={`scope-package-${index}`}
                                        list={`packages-${index}`}
                                        value={scope.package ?? ''}
                                        placeholder={t(
                                            'kyc.document_types.scopes.package_placeholder',
                                        )}
                                        onChange={(event) =>
                                            update(index, {
                                                package:
                                                    event.target.value || null,
                                            })
                                        }
                                    />
                                    <datalist id={`packages-${index}`}>
                                        {packages.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </datalist>
                                </>
                            ) : (
                                <p className="text-muted-foreground text-xs">
                                    {t(
                                        'kyc.document_types.scopes.packages_pending',
                                    )}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-1">
                            <Label
                                htmlFor={`scope-country-${index}`}
                                className="text-xs"
                            >
                                {t('kyc.document_types.scopes.country')}
                            </Label>

                            {/*
                             * Chosen, never typed. "Bangladsh" looks configured
                             * and matches nobody.
                             */}
                            <select
                                id={`scope-country-${index}`}
                                value={scope.country ?? ''}
                                onChange={(event) =>
                                    update(index, {
                                        country: event.target.value || null,
                                    })
                                }
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="">
                                    {t(
                                        'kyc.document_types.scopes.country_placeholder',
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

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`scope-required-${index}`}
                                className="text-xs"
                            >
                                {t('kyc.document_types.scopes.requirement')}
                            </Label>
                            <select
                                id={`scope-required-${index}`}
                                value={
                                    scope.is_required === null
                                        ? 'inherit'
                                        : scope.is_required
                                          ? 'required'
                                          : 'optional'
                                }
                                onChange={(event) =>
                                    update(index, {
                                        is_required:
                                            event.target.value === 'inherit'
                                                ? null
                                                : event.target.value ===
                                                  'required',
                                    })
                                }
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            >
                                <option value="inherit">
                                    {t('kyc.document_types.scopes.inherit')}
                                </option>
                                <option value="required">
                                    {t('kyc.document_types.state.required')}
                                </option>
                                <option value="optional">
                                    {t('kyc.document_types.state.optional')}
                                </option>
                            </select>
                        </div>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                onChange(scopes.filter((_, i) => i !== index))
                            }
                        >
                            <X className="size-4" />
                            {t('kyc.document_types.scopes.remove')}
                        </Button>
                    </div>

                    <InputError message={errors[`scopes.${index}.package`]} />
                    <InputError message={errors[`scopes.${index}.country`]} />
                </div>
            ))}

            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                    onChange([
                        ...scopes,
                        { package: null, country: null, is_required: null },
                    ])
                }
            >
                <Plus className="size-4" />
                {t('kyc.document_types.scopes.add')}
            </Button>
        </fieldset>
    );
}
