import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Copy,
    Eye,
    Plus,
    Power,
    Star,
    Trash2,
    Upload,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import type { ReactNode } from 'react';
import NativeSelect from '@/components/forms/native-select';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import StructureOutline from '@/components/kyc/structure-outline';
import { useTranslation } from '@/hooks/use-translation';
import {
    active,
    clone,
    defaultMethod,
    index,
    preview,
    publish,
    structure,
    update,
} from '@/routes/admin/kyc/batch-builder';
import type {
    KycBatchRule,
    KycBuilderBatch,
    KycOutline,
    KycBuilderGroup,
    KycBuilderRequirement,
    KycBuilderSlot,
    SelectOption,
} from '@/types';

type Options = {
    audiences: SelectOption[];
    selfie_modes: SelectOption[];
    rules: SelectOption[];
    evidence_types: SelectOption[];
    packages: SelectOption[];
    countries: SelectOption[];
};

/** The formats a slot of each kind may be limited to. */
const FORMATS: Record<string, { value: string; label: string }[]> = {
    image: [
        { value: 'image/jpeg', label: 'JPEG' },
        { value: 'image/png', label: 'PNG' },
    ],
    pdf: [{ value: 'application/pdf', label: 'PDF' }],
    file: [
        { value: 'image/jpeg', label: 'JPEG' },
        { value: 'image/png', label: 'PNG' },
        { value: 'application/pdf', label: 'PDF' },
    ],
};

const newSlot = (): KycBuilderSlot => ({
    id: null,
    label: '',
    label_bn: null,
    instructions: null,
    instructions_bn: null,
    required: true,
    evidence: 'image',
    mimes: [],
    max_kb: null,
    value_label: null,
    value_label_bn: null,
    sensitive: false,
});

const newRequirement = (): KycBuilderRequirement => ({
    id: null,
    name: '',
    name_bn: null,
    instructions: null,
    instructions_bn: null,
    required: true,
    slots: [newSlot()],
});

const newGroup = (): KycBuilderGroup => ({
    id: null,
    name: '',
    name_bn: null,
    description: null,
    description_bn: null,
    selector_label: null,
    selector_label_bn: null,
    rule: 'all',
    minimum: null,
    required: true,
    requirements: [newRequirement()],
});

function move<T>(list: T[], from: number, to: number): T[] {
    if (to < 0 || to >= list.length) {
        return list;
    }

    const next = [...list];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);

    return next;
}

function Field({
    label,
    hint,
    children,
    className,
}: {
    label: string;
    hint?: string;
    children: (id: string) => ReactNode;
    className?: string;
}) {
    const id = useId();

    return (
        <div className={className ?? 'space-y-1.5'}>
            <Label htmlFor={id}>{label}</Label>
            {children(id)}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </div>
    );
}

function Check({
    checked,
    onChange,
    children,
    disabled,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    children: ReactNode;
    disabled?: boolean;
}) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="accent-brand size-4"
            />
            {children}
        </label>
    );
}

/**
 * The KYC batch builder (D30): a batch's details, then its groups, the
 * requirements in each, and the evidence slots of each requirement.
 *
 * Everything is edited on the *live* structure and saved with one button; nothing
 * an applicant sees changes until the batch is published, and a round already open
 * keeps the version it was opened on. The page says plainly when the two differ,
 * and lists — in one place — whatever would stop the batch being published.
 *
 * Whether a batch can be completed is decided by the server when it is published.
 * This page only sends what the administrator built.
 */
export default function KycBatchBuilder({
    batch,
    groups: savedGroups,
    problems,
    options,
    can,
    outline,
}: {
    batch: KycBuilderBatch;
    groups: KycBuilderGroup[];
    problems: string[];
    options: Options;
    /** The saved structure drawn as a tree: groups, options, evidence slots. */
    outline: KycOutline;
    /** What this viewer may do with this batch. */
    can: { create: boolean; update: boolean; activate: boolean };
}) {
    const { t } = useTranslation();
    const [groups, setGroups] = useState<KycBuilderGroup[]>(savedGroups);
    const [details, setDetails] = useState({
        name: batch.name,
        name_bn: batch.name_bn ?? '',
        description: batch.description ?? '',
        description_bn: batch.description_bn ?? '',
        audience: batch.audience,
        selfie_mode: batch.selfie_mode,
        selfie_allows_upload: batch.selfie_allows_upload,
        rules: batch.rules,
    });
    const [busy, setBusy] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // What the server saved replaces what is on screen: new items get their ids.
    useEffect(() => setGroups(savedGroups), [savedGroups]);
    useEffect(
        () =>
            setDetails({
                name: batch.name,
                name_bn: batch.name_bn ?? '',
                description: batch.description ?? '',
                description_bn: batch.description_bn ?? '',
                audience: batch.audience,
                selfie_mode: batch.selfie_mode,
                selfie_allows_upload: batch.selfie_allows_upload,
                rules: batch.rules,
            }),
        [batch],
    );

    const dirty = JSON.stringify(groups) !== JSON.stringify(savedGroups);

    const request = (
        key: string,
        run: (callbacks: {
            preserveScroll: boolean;
            onStart: () => void;
            onError: (errors: Record<string, string>) => void;
            onFinish: () => void;
        }) => void,
    ) =>
        run({
            preserveScroll: true,
            onStart: () => {
                setBusy(key);
                setErrors({});
            },
            onError: (failed) => setErrors(failed),
            onFinish: () => setBusy(null),
        });

    const mutate = (change: (draft: KycBuilderGroup[]) => void) =>
        setGroups((current) => {
            const draft = structuredClone(current);
            change(draft);

            return draft;
        });

    const errorLines = Object.entries(errors).flatMap(([, message]) =>
        Array.isArray(message) ? message : [message],
    );

    const isPublishable = problems.length === 0;

    return (
        <>
            <Head title={t('kyc.builder.title_edit')} />

            <PageContainer>
                <PageHeader
                    title={batch.name}
                    description={t('kyc.builder.title_edit')}
                    actions={
                        <>
                            <StatusPill
                                tone={batch.is_active ? 'success' : 'neutral'}
                                label={t(
                                    batch.is_active
                                        ? 'kyc.builder.status_active'
                                        : 'kyc.builder.status_off',
                                )}
                            />
                            {batch.is_default && (
                                <StatusPill
                                    tone="info"
                                    icon={Star}
                                    label={t('kyc.builder.default_badge')}
                                />
                            )}
                            <StatusPill
                                tone={
                                    batch.published_version === 0
                                        ? 'neutral'
                                        : batch.has_unpublished_changes
                                          ? 'warning'
                                          : 'success'
                                }
                                label={
                                    batch.published_version === 0
                                        ? t('kyc.builder.not_published')
                                        : batch.has_unpublished_changes
                                          ? t('kyc.builder.unpublished_changes')
                                          : t('kyc.builder.version', {
                                                version:
                                                    batch.published_version,
                                            })
                                }
                            />
                            <Button variant="outline" size="sm" asChild>
                                <Link href={index()}>
                                    <ArrowLeft
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {t('kyc.builder.back_to_list')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={preview(batch.id)}>
                            <Eye className="size-4" aria-hidden="true" />
                            {t('kyc.builder.preview')}
                        </Link>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={busy !== null || !can.create}
                        onClick={() =>
                            request('clone', (callbacks) =>
                                router.post(clone.url(batch.id), {}, callbacks),
                            )
                        }
                    >
                        <Copy className="size-4" aria-hidden="true" />
                        {t('kyc.builder.clone')}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={
                            busy !== null ||
                            !can.activate ||
                            dirty ||
                            !isPublishable ||
                            (batch.published_version > 0 &&
                                !batch.has_unpublished_changes)
                        }
                        onClick={() =>
                            request('publish', (callbacks) =>
                                router.post(
                                    publish.url(batch.id),
                                    {},
                                    callbacks,
                                ),
                            )
                        }
                    >
                        {busy === 'publish' ? (
                            <Spinner className="size-4" />
                        ) : (
                            <Upload className="size-4" aria-hidden="true" />
                        )}
                        {t('kyc.builder.publish')}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={
                            busy !== null ||
                            !can.activate ||
                            dirty ||
                            (!batch.is_active && !isPublishable)
                        }
                        onClick={() =>
                            request('active', (callbacks) =>
                                router.patch(
                                    active.url(batch.id),
                                    { is_active: !batch.is_active },
                                    callbacks,
                                ),
                            )
                        }
                    >
                        <Power className="size-4" aria-hidden="true" />
                        {t(
                            batch.is_active
                                ? 'kyc.builder.deactivate'
                                : 'kyc.builder.activate',
                        )}
                    </Button>
                    {batch.is_active && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={busy !== null || !can.activate}
                            onClick={() =>
                                request('default', (callbacks) =>
                                    router.patch(
                                        defaultMethod.url(batch.id),
                                        { is_default: !batch.is_default },
                                        callbacks,
                                    ),
                                )
                            }
                        >
                            <Star className="size-4" aria-hidden="true" />
                            {t(
                                batch.is_default
                                    ? 'kyc.builder.remove_default'
                                    : 'kyc.builder.make_default',
                            )}
                        </Button>
                    )}
                </div>

                {dirty && (
                    <p role="status" className="text-muted-foreground text-xs">
                        {t('kyc.builder.save_before_publishing')}
                    </p>
                )}

                {(problems.length > 0 || errorLines.length > 0) && (
                    <section
                        role="alert"
                        className="bg-warning-subtle border-warning/30 space-y-1 rounded-lg border p-4"
                    >
                        <p className="text-sm font-semibold">
                            {t('kyc.builder.problems_title')}
                        </p>
                        <ul className="list-disc space-y-0.5 pl-5 text-sm">
                            {[...errorLines, ...problems].map((line, i) => (
                                <li key={`${i}-${line}`}>{line}</li>
                            ))}
                        </ul>
                    </section>
                )}

                <SectionCard
                    title={t('kyc.builder.outline_title')}
                    description={t('kyc.builder.outline_help')}
                >
                    <StructureOutline
                        outline={outline}
                        selfie={batch.selfie_mode}
                    />
                </SectionCard>

                <SectionCard
                    title={t('kyc.builder.details_title')}
                    description={t('kyc.builder.details_help')}
                >
                    <fieldset disabled={!can.update} className="contents">
                        <form
                            className="grid gap-4 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                request('details', (callbacks) =>
                                    router.patch(
                                        update.url(batch.id),
                                        details as never,
                                        callbacks,
                                    ),
                                );
                            }}
                        >
                            <Field label={t('kyc.builder.name')}>
                                {(id) => (
                                    <Input
                                        id={id}
                                        value={details.name}
                                        required
                                        maxLength={120}
                                        onChange={(event) =>
                                            setDetails({
                                                ...details,
                                                name: event.target.value,
                                            })
                                        }
                                    />
                                )}
                            </Field>
                            <Field label={t('kyc.builder.name_bn')}>
                                {(id) => (
                                    <Input
                                        id={id}
                                        lang="bn"
                                        value={details.name_bn}
                                        maxLength={120}
                                        onChange={(event) =>
                                            setDetails({
                                                ...details,
                                                name_bn: event.target.value,
                                            })
                                        }
                                    />
                                )}
                            </Field>
                            <Field label={t('kyc.builder.description')}>
                                {(id) => (
                                    <textarea
                                        id={id}
                                        rows={2}
                                        maxLength={1000}
                                        value={details.description}
                                        onChange={(event) =>
                                            setDetails({
                                                ...details,
                                                description: event.target.value,
                                            })
                                        }
                                        className="border-input focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                    />
                                )}
                            </Field>
                            <Field label={t('kyc.builder.description_bn')}>
                                {(id) => (
                                    <textarea
                                        id={id}
                                        lang="bn"
                                        rows={2}
                                        maxLength={1000}
                                        value={details.description_bn}
                                        onChange={(event) =>
                                            setDetails({
                                                ...details,
                                                description_bn:
                                                    event.target.value,
                                            })
                                        }
                                        className="border-input focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                    />
                                )}
                            </Field>
                            <Field
                                label={t('kyc.builder.audience')}
                                hint={t('kyc.builder.audience_help')}
                            >
                                {(id) => (
                                    <NativeSelect
                                        id={id}
                                        value={details.audience}
                                        onChange={(event) =>
                                            setDetails({
                                                ...details,
                                                audience: event.target
                                                    .value as typeof details.audience,
                                            })
                                        }
                                    >
                                        {options.audiences.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                )}
                            </Field>
                            <Field
                                label={t('kyc.builder.selfie')}
                                hint={t('kyc.builder.selfie_help')}
                            >
                                {(id) => (
                                    <NativeSelect
                                        id={id}
                                        value={details.selfie_mode}
                                        onChange={(event) =>
                                            setDetails({
                                                ...details,
                                                selfie_mode: event.target
                                                    .value as typeof details.selfie_mode,
                                            })
                                        }
                                    >
                                        {options.selfie_modes.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                )}
                            </Field>

                            {details.selfie_mode !== 'disabled' && (
                                <div className="sm:col-span-2">
                                    <Check
                                        checked={details.selfie_allows_upload}
                                        onChange={(checked) =>
                                            setDetails({
                                                ...details,
                                                selfie_allows_upload: checked,
                                            })
                                        }
                                    >
                                        {t('kyc.builder.selfie_upload')}
                                    </Check>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {t('kyc.builder.selfie_upload_help')}
                                    </p>
                                </div>
                            )}

                            {details.audience !== 'supplier' && (
                                <fieldset className="space-y-2 sm:col-span-2">
                                    <legend className="text-sm font-medium">
                                        {t('kyc.builder.applicability')}
                                    </legend>
                                    <p className="text-muted-foreground text-xs">
                                        {t('kyc.builder.applicability_help')}
                                    </p>

                                    {details.rules.map((rule, i) => (
                                        <RuleRow
                                            key={i}
                                            rule={rule}
                                            options={options}
                                            onChange={(next) =>
                                                setDetails({
                                                    ...details,
                                                    rules: details.rules.map(
                                                        (one, at) =>
                                                            at === i
                                                                ? next
                                                                : one,
                                                    ),
                                                })
                                            }
                                            onRemove={() =>
                                                setDetails({
                                                    ...details,
                                                    rules: details.rules.filter(
                                                        (_, at) => at !== i,
                                                    ),
                                                })
                                            }
                                        />
                                    ))}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setDetails({
                                                ...details,
                                                rules: [
                                                    ...details.rules,
                                                    {
                                                        package: null,
                                                        country: null,
                                                    },
                                                ],
                                            })
                                        }
                                    >
                                        <Plus
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        {t('kyc.builder.add_rule')}
                                    </Button>
                                </fieldset>
                            )}

                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={busy !== null}>
                                    {busy === 'details' && <Spinner />}
                                    {t('kyc.builder.save_details')}
                                </Button>
                            </div>
                        </form>
                    </fieldset>
                </SectionCard>

                <SectionCard
                    title={t('kyc.builder.groups_title')}
                    description={t('kyc.builder.groups_help')}
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                mutate((draft) => {
                                    draft.push(newGroup());
                                })
                            }
                        >
                            <Plus className="size-4" aria-hidden="true" />
                            {t('kyc.builder.add_group')}
                        </Button>
                    }
                >
                    <fieldset
                        disabled={!can.update}
                        className="m-0 min-w-0 border-0 p-0"
                    >
                        <div className="space-y-6">
                            {groups.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    {t('kyc.builder.empty_groups')}
                                </p>
                            )}

                            {groups.map((group, gi) => (
                                <GroupEditor
                                    key={group.id ?? `new-${gi}`}
                                    group={group}
                                    position={gi}
                                    total={groups.length}
                                    options={options}
                                    mutate={(change) =>
                                        mutate((draft) =>
                                            change(draft[gi], draft),
                                        )
                                    }
                                    onMove={(to) =>
                                        mutate((draft) => {
                                            const moved = move(draft, gi, to);
                                            draft.splice(
                                                0,
                                                draft.length,
                                                ...moved,
                                            );
                                        })
                                    }
                                    onRemove={() =>
                                        mutate((draft) => {
                                            draft.splice(gi, 1);
                                        })
                                    }
                                />
                            ))}

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="button"
                                    disabled={busy !== null || !dirty}
                                    onClick={() =>
                                        request('structure', (callbacks) =>
                                            router.put(
                                                structure.url(batch.id),
                                                { groups } as never,
                                                callbacks,
                                            ),
                                        )
                                    }
                                >
                                    {busy === 'structure' && <Spinner />}
                                    {t('kyc.builder.save_structure')}
                                </Button>
                                {dirty && (
                                    <span className="text-muted-foreground text-xs">
                                        {t('kyc.builder.unsaved')}
                                    </span>
                                )}
                            </div>
                        </div>
                    </fieldset>
                </SectionCard>
            </PageContainer>
        </>
    );
}

function RuleRow({
    rule,
    options,
    onChange,
    onRemove,
}: {
    rule: KycBatchRule;
    options: Options;
    onChange: (rule: KycBatchRule) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap items-end gap-2">
            <Field
                label={t('kyc.builder.package')}
                className="min-w-40 flex-1 space-y-1.5"
            >
                {(id) => (
                    <NativeSelect
                        id={id}
                        value={rule.package ?? ''}
                        onChange={(event) =>
                            onChange({
                                ...rule,
                                package: event.target.value || null,
                            })
                        }
                    >
                        <option value="">{t('kyc.builder.any_package')}</option>
                        {options.packages.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                )}
            </Field>
            <Field
                label={t('kyc.builder.country')}
                className="min-w-40 flex-1 space-y-1.5"
            >
                {(id) => (
                    <NativeSelect
                        id={id}
                        value={rule.country ?? ''}
                        onChange={(event) =>
                            onChange({
                                ...rule,
                                country: event.target.value || null,
                            })
                        }
                    >
                        <option value="">{t('kyc.builder.any_country')}</option>
                        {options.countries.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                )}
            </Field>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onRemove}
                aria-label={t('kyc.builder.remove')}
            >
                <Trash2 className="size-4" />
            </Button>
        </div>
    );
}

function Mover({
    position,
    total,
    onMove,
    onRemove,
    label,
}: {
    position: number;
    total: number;
    onMove: (to: number) => void;
    onRemove: () => void;
    label: string;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex items-center gap-1">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7"
                disabled={position === 0}
                onClick={() => onMove(position - 1)}
                aria-label={`${t('kyc.builder.move_up')}: ${label}`}
            >
                <ArrowUp className="size-3.5" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7"
                disabled={position === total - 1}
                onClick={() => onMove(position + 1)}
                aria-label={`${t('kyc.builder.move_down')}: ${label}`}
            >
                <ArrowDown className="size-3.5" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-7"
                onClick={onRemove}
                aria-label={`${t('kyc.builder.remove')}: ${label}`}
            >
                <Trash2 className="size-3.5" />
            </Button>
        </div>
    );
}

function GroupEditor({
    group,
    position,
    total,
    options,
    mutate,
    onMove,
    onRemove,
}: {
    group: KycBuilderGroup;
    position: number;
    total: number;
    options: Options;
    mutate: (
        change: (group: KycBuilderGroup, all: KycBuilderGroup[]) => void,
    ) => void;
    onMove: (to: number) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const label = group.name || t('kyc.builder.group');

    return (
        <section aria-label={label} className="space-y-4 rounded-xl border p-4">
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-sm font-semibold">
                    {t('kyc.builder.group')} {position + 1}
                </h3>
                <Mover
                    position={position}
                    total={total}
                    onMove={onMove}
                    onRemove={onRemove}
                    label={label}
                />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('kyc.builder.group_name')}>
                    {(id) => (
                        <Input
                            id={id}
                            value={group.name}
                            maxLength={120}
                            onChange={(event) =>
                                mutate((g) => {
                                    g.name = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.name_bn')}>
                    {(id) => (
                        <Input
                            id={id}
                            lang="bn"
                            value={group.name_bn ?? ''}
                            maxLength={120}
                            onChange={(event) =>
                                mutate((g) => {
                                    g.name_bn = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.description')}>
                    {(id) => (
                        <Input
                            id={id}
                            value={group.description ?? ''}
                            maxLength={500}
                            onChange={(event) =>
                                mutate((g) => {
                                    g.description = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.description_bn')}>
                    {(id) => (
                        <Input
                            id={id}
                            lang="bn"
                            value={group.description_bn ?? ''}
                            maxLength={500}
                            onChange={(event) =>
                                mutate((g) => {
                                    g.description_bn = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field
                    label={t('kyc.builder.group_rule')}
                    hint={t(`kyc.builder.group_rule_help_${group.rule}`)}
                >
                    {(id) => (
                        <NativeSelect
                            id={id}
                            value={group.rule}
                            onChange={(event) =>
                                mutate((g) => {
                                    g.rule = event.target
                                        .value as KycBuilderGroup['rule'];
                                    g.minimum =
                                        g.rule === 'min'
                                            ? (g.minimum ?? 2)
                                            : null;
                                })
                            }
                        >
                            {options.rules.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    )}
                </Field>
                {group.rule !== 'all' && (
                    <>
                        <Field
                            label={t('kyc.builder.selector_label')}
                            hint={t('kyc.builder.selector_label_help')}
                        >
                            {(id) => (
                                <Input
                                    id={id}
                                    value={group.selector_label ?? ''}
                                    maxLength={120}
                                    placeholder={t('kyc.batch_form.select_one')}
                                    onChange={(event) =>
                                        mutate((g) => {
                                            g.selector_label =
                                                event.target.value;
                                        })
                                    }
                                />
                            )}
                        </Field>
                        <Field label={t('kyc.builder.selector_label_bn')}>
                            {(id) => (
                                <Input
                                    id={id}
                                    lang="bn"
                                    value={group.selector_label_bn ?? ''}
                                    maxLength={120}
                                    onChange={(event) =>
                                        mutate((g) => {
                                            g.selector_label_bn =
                                                event.target.value;
                                        })
                                    }
                                />
                            )}
                        </Field>
                    </>
                )}
                {group.rule === 'min' && (
                    <Field label={t('kyc.builder.group_minimum')}>
                        {(id) => (
                            <Input
                                id={id}
                                type="number"
                                min={1}
                                max={20}
                                value={group.minimum ?? ''}
                                onChange={(event) =>
                                    mutate((g) => {
                                        g.minimum = event.target.value
                                            ? Number(event.target.value)
                                            : null;
                                    })
                                }
                            />
                        )}
                    </Field>
                )}
                <div className="flex items-end">
                    <Check
                        checked={group.required}
                        onChange={(checked) =>
                            mutate((g) => {
                                g.required = checked;
                            })
                        }
                    >
                        {t('kyc.builder.group_required')}
                    </Check>
                </div>
            </div>

            <div className="space-y-4">
                {group.requirements.length === 0 && (
                    <p className="text-muted-foreground text-sm">
                        {t('kyc.builder.empty_requirements')}
                    </p>
                )}

                {group.requirements.map((requirement, ri) => (
                    <RequirementEditor
                        key={requirement.id ?? `new-${ri}`}
                        requirement={requirement}
                        position={ri}
                        total={group.requirements.length}
                        // "Required" only means something in an all-required group;
                        // elsewhere every option counts towards the minimum.
                        showRequired={group.rule === 'all'}
                        mutate={(change) =>
                            mutate((g) => change(g.requirements[ri]))
                        }
                        onMove={(to) =>
                            mutate((g) => {
                                g.requirements = move(g.requirements, ri, to);
                            })
                        }
                        onRemove={() =>
                            mutate((g) => {
                                g.requirements.splice(ri, 1);
                            })
                        }
                        options={options}
                    />
                ))}

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        mutate((g) => {
                            g.requirements.push(newRequirement());
                        })
                    }
                >
                    <Plus className="size-4" aria-hidden="true" />
                    {t('kyc.builder.add_requirement')}
                </Button>
            </div>
        </section>
    );
}

function RequirementEditor({
    requirement,
    position,
    total,
    showRequired,
    options,
    mutate,
    onMove,
    onRemove,
}: {
    requirement: KycBuilderRequirement;
    position: number;
    total: number;
    showRequired: boolean;
    options: Options;
    mutate: (change: (requirement: KycBuilderRequirement) => void) => void;
    onMove: (to: number) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const label = requirement.name || t('kyc.builder.requirement');

    return (
        <div className="bg-surface-subtle space-y-3 rounded-lg border p-3">
            <div className="flex items-start justify-between gap-3">
                <h4 className="text-sm font-medium">
                    {t('kyc.builder.requirement')} {position + 1}
                </h4>
                <Mover
                    position={position}
                    total={total}
                    onMove={onMove}
                    onRemove={onRemove}
                    label={label}
                />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('kyc.builder.requirement_name')}>
                    {(id) => (
                        <Input
                            id={id}
                            value={requirement.name}
                            maxLength={120}
                            onChange={(event) =>
                                mutate((r) => {
                                    r.name = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.name_bn')}>
                    {(id) => (
                        <Input
                            id={id}
                            lang="bn"
                            value={requirement.name_bn ?? ''}
                            maxLength={120}
                            onChange={(event) =>
                                mutate((r) => {
                                    r.name_bn = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.instructions')}>
                    {(id) => (
                        <Input
                            id={id}
                            value={requirement.instructions ?? ''}
                            maxLength={1000}
                            onChange={(event) =>
                                mutate((r) => {
                                    r.instructions = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.instructions_bn')}>
                    {(id) => (
                        <Input
                            id={id}
                            lang="bn"
                            value={requirement.instructions_bn ?? ''}
                            maxLength={1000}
                            onChange={(event) =>
                                mutate((r) => {
                                    r.instructions_bn = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                {showRequired && (
                    <Check
                        checked={requirement.required}
                        onChange={(checked) =>
                            mutate((r) => {
                                r.required = checked;
                            })
                        }
                    >
                        {t('kyc.builder.requirement_required')}
                    </Check>
                )}
            </div>

            <div className="space-y-3">
                <p className="text-xs font-medium tracking-wide uppercase">
                    {t('kyc.builder.slots_title')}
                </p>

                {requirement.slots.length === 0 && (
                    <p className="text-muted-foreground text-sm">
                        {t('kyc.builder.empty_slots')}
                    </p>
                )}

                {requirement.slots.map((slot, si) => (
                    <SlotEditor
                        key={slot.id ?? `new-${si}`}
                        slot={slot}
                        position={si}
                        total={requirement.slots.length}
                        options={options}
                        mutate={(change) => mutate((r) => change(r.slots[si]))}
                        onMove={(to) =>
                            mutate((r) => {
                                r.slots = move(r.slots, si, to);
                            })
                        }
                        onRemove={() =>
                            mutate((r) => {
                                r.slots.splice(si, 1);
                            })
                        }
                    />
                ))}

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        mutate((r) => {
                            r.slots.push(newSlot());
                        })
                    }
                >
                    <Plus className="size-4" aria-hidden="true" />
                    {t('kyc.builder.add_slot')}
                </Button>
            </div>
        </div>
    );
}

function SlotEditor({
    slot,
    position,
    total,
    options,
    mutate,
    onMove,
    onRemove,
}: {
    slot: KycBuilderSlot;
    position: number;
    total: number;
    options: Options;
    mutate: (change: (slot: KycBuilderSlot) => void) => void;
    onMove: (to: number) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslation();
    const isFile = ['file', 'image', 'pdf'].includes(slot.evidence);
    const label = slot.label || t('kyc.builder.slot');

    return (
        <div className="bg-card space-y-3 rounded-md border p-3">
            <div className="flex items-start justify-between gap-3">
                <h5 className="text-sm font-medium">
                    {t('kyc.builder.slot')} {position + 1}
                </h5>
                <Mover
                    position={position}
                    total={total}
                    onMove={onMove}
                    onRemove={onRemove}
                    label={label}
                />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('kyc.builder.slot_label')}>
                    {(id) => (
                        <Input
                            id={id}
                            value={slot.label}
                            maxLength={120}
                            onChange={(event) =>
                                mutate((s) => {
                                    s.label = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.name_bn')}>
                    {(id) => (
                        <Input
                            id={id}
                            lang="bn"
                            value={slot.label_bn ?? ''}
                            maxLength={120}
                            onChange={(event) =>
                                mutate((s) => {
                                    s.label_bn = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.instructions')}>
                    {(id) => (
                        <Input
                            id={id}
                            value={slot.instructions ?? ''}
                            maxLength={500}
                            onChange={(event) =>
                                mutate((s) => {
                                    s.instructions = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.instructions_bn')}>
                    {(id) => (
                        <Input
                            id={id}
                            lang="bn"
                            value={slot.instructions_bn ?? ''}
                            maxLength={500}
                            onChange={(event) =>
                                mutate((s) => {
                                    s.instructions_bn = event.target.value;
                                })
                            }
                        />
                    )}
                </Field>
                <Field label={t('kyc.builder.slot_evidence')}>
                    {(id) => (
                        <NativeSelect
                            id={id}
                            value={
                                slot.evidence === 'value' && slot.sensitive
                                    ? 'value_sensitive'
                                    : slot.evidence
                            }
                            onChange={(event) =>
                                mutate((s) => {
                                    const kind = event.target.value;

                                    // A sensitive typed value is a typed value that is
                                    // masked on screen and revealed only on request.
                                    s.evidence = (
                                        kind === 'value_sensitive'
                                            ? 'value'
                                            : kind
                                    ) as KycBuilderSlot['evidence'];
                                    s.sensitive = kind === 'value_sensitive';
                                    // The old formats may not suit the new kind.
                                    s.mimes = [];
                                })
                            }
                        >
                            {options.evidence_types
                                .flatMap((option) =>
                                    option.value === 'value'
                                        ? [
                                              option,
                                              {
                                                  value: 'value_sensitive',
                                                  label: t(
                                                      'kyc.builder.kind_sensitive',
                                                  ),
                                              },
                                          ]
                                        : [option],
                                )
                                .map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                        </NativeSelect>
                    )}
                </Field>
                <div className="flex items-end">
                    <Check
                        checked={slot.required}
                        onChange={(checked) =>
                            mutate((s) => {
                                s.required = checked;
                            })
                        }
                    >
                        {t('kyc.builder.slot_required')}
                    </Check>
                </div>

                {isFile && (
                    <>
                        <fieldset className="space-y-1.5">
                            <legend className="text-sm font-medium">
                                {t('kyc.builder.slot_formats')}
                            </legend>
                            <div className="flex flex-wrap gap-4">
                                {FORMATS[slot.evidence].map((format) => (
                                    <Check
                                        key={format.value}
                                        checked={slot.mimes.includes(
                                            format.value,
                                        )}
                                        onChange={(checked) =>
                                            mutate((s) => {
                                                s.mimes = checked
                                                    ? [...s.mimes, format.value]
                                                    : s.mimes.filter(
                                                          (mime) =>
                                                              mime !==
                                                              format.value,
                                                      );
                                            })
                                        }
                                    >
                                        {format.label}
                                    </Check>
                                ))}
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {t('kyc.builder.slot_formats_help')}
                            </p>
                        </fieldset>
                        <Field label={t('kyc.builder.slot_max')}>
                            {(id) => (
                                <Input
                                    id={id}
                                    type="number"
                                    min={1}
                                    max={20}
                                    value={
                                        slot.max_kb
                                            ? Math.round(slot.max_kb / 1024)
                                            : ''
                                    }
                                    onChange={(event) =>
                                        mutate((s) => {
                                            s.max_kb = event.target.value
                                                ? Number(event.target.value) *
                                                  1024
                                                : null;
                                        })
                                    }
                                />
                            )}
                        </Field>
                    </>
                )}

                {slot.evidence === 'value' && (
                    <>
                        <Field label={t('kyc.builder.slot_value_label')}>
                            {(id) => (
                                <Input
                                    id={id}
                                    value={slot.value_label ?? ''}
                                    maxLength={120}
                                    onChange={(event) =>
                                        mutate((s) => {
                                            s.value_label = event.target.value;
                                        })
                                    }
                                />
                            )}
                        </Field>
                        <Field label={t('kyc.builder.slot_value_label_bn')}>
                            {(id) => (
                                <Input
                                    id={id}
                                    lang="bn"
                                    value={slot.value_label_bn ?? ''}
                                    maxLength={120}
                                    onChange={(event) =>
                                        mutate((s) => {
                                            s.value_label_bn =
                                                event.target.value;
                                        })
                                    }
                                />
                            )}
                        </Field>
                    </>
                )}
            </div>
        </div>
    );
}

KycBatchBuilder.layout = {
    breadcrumbs: [
        {
            title: 'nav.kyc_batches',
            href: index(),
        },
    ],
};
