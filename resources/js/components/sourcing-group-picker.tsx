import { useHttp } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import SourcingGroupController from '@/actions/App/Http/Controllers/Admin/SourcingGroupController';
import FormField from '@/components/forms/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

export type SourcingGroupOption = {
    id: string;
    code: string;
    name_en: string;
    name_bn: string;
    canonical_product: { name: string; sku: string } | null;
    canonical_variants: { id: string; label: string }[];
};

type Props = {
    groups: SourcingGroupOption[];
    value: string;
    onChange: (id: string) => void;
    /** Whether the viewer may create a new platform group from here. */
    canCreate: boolean;
    /** The group the connected product already belongs to, if any. */
    lockedTo?: string | null;
    error?: string;
    /** Called with a group just created from the dialog, before it is selected. */
    onCreated?: (group: SourcingGroupOption) => void;
};

/**
 * Choose which Product Sourcing Group an approved Supplier offer fulfils
 * orders through, or create one without leaving the page.
 *
 * Staff-only: a Supplier never sees or picks a platform group. A product that
 * already sits in a group keeps it, so the choice is locked rather than
 * offered and then refused.
 */
export default function SourcingGroupPicker({
    groups: initialGroups,
    value,
    onChange,
    canCreate,
    lockedTo = null,
    error,
    onCreated,
}: Props) {
    const { t, locale } = useTranslation();
    const [groups, setGroups] = useState(initialGroups);
    const [query, setQuery] = useState('');
    const [creating, setCreating] = useState(false);

    const name = (group: SourcingGroupOption) =>
        locale === 'bn' ? group.name_bn : group.name_en;

    const selected = groups.find((group) => group.id === (lockedTo ?? value));

    const matches = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return groups
            .filter(
                (group) =>
                    needle === '' ||
                    group.code.toLowerCase().includes(needle) ||
                    group.name_en.toLowerCase().includes(needle) ||
                    group.name_bn.toLowerCase().includes(needle),
            )
            .slice(0, 8);
    }, [groups, query]);

    return (
        <fieldset className="space-y-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">
                {t('sourcing.picker.heading')}
            </legend>
            <p className="text-muted-foreground text-xs">
                {t('sourcing.picker.help')}
            </p>

            {lockedTo !== null ? (
                <p className="text-sm">
                    {t('sourcing.picker.locked', {
                        name: selected ? name(selected) : lockedTo,
                    })}
                </p>
            ) : (
                <>
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t('sourcing.picker.search')}
                        aria-label={t('sourcing.picker.search')}
                    />

                    <ul
                        role="listbox"
                        aria-label={t('sourcing.picker.heading')}
                        className="divide-border divide-y rounded-md border"
                    >
                        {matches.length === 0 && (
                            <li className="text-muted-foreground p-2 text-sm">
                                {t('sourcing.picker.none')}
                            </li>
                        )}
                        {matches.map((group) => (
                            <li
                                key={group.id}
                                role="option"
                                aria-selected={group.id === value}
                            >
                                <button
                                    type="button"
                                    onClick={() => onChange(group.id)}
                                    className={`hover:bg-muted flex w-full items-start gap-2 p-2 text-left text-sm ${
                                        group.id === value ? 'bg-muted' : ''
                                    }`}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {name(group)}
                                        </span>
                                        <span className="text-muted-foreground block truncate text-xs">
                                            {group.code}
                                            {group.canonical_product
                                                ? ` · ${group.canonical_product.name}`
                                                : ` · ${t('sourcing.picker.empty_group')}`}
                                        </span>
                                    </span>
                                    {group.id === value && (
                                        <span className="text-xs font-medium">
                                            {t('sourcing.picker.selected')}
                                        </span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>

                    {canCreate && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setCreating(true)}
                        >
                            <Plus className="size-4" aria-hidden="true" />
                            {t('sourcing.picker.create')}
                        </Button>
                    )}
                </>
            )}

            {selected?.canonical_product && (
                <p className="text-muted-foreground text-xs">
                    {t('sourcing.picker.canonical', {
                        product: `${selected.canonical_product.name} (${selected.canonical_product.sku})`,
                    })}
                    {selected.canonical_variants.length > 0 &&
                        ` · ${selected.canonical_variants
                            .map((variant) => variant.label)
                            .join(', ')}`}
                </p>
            )}

            {error && (
                <p role="alert" className="text-danger text-sm">
                    {error}
                </p>
            )}

            <CreateGroupDialog
                open={creating}
                onOpenChange={setCreating}
                onCreated={(group) => {
                    setGroups((current) => [group, ...current]);
                    onCreated?.(group);
                    onChange(group.id);
                    setCreating(false);
                }}
            />
        </fieldset>
    );
}

/**
 * Creates a group over JSON and hands it straight back, so the reviewer keeps
 * their half-filled decision: nothing navigates away.
 */
function CreateGroupDialog({
    open,
    onOpenChange,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCreated: (group: SourcingGroupOption) => void;
}) {
    const { t } = useTranslation();
    const http = useHttp<
        { code: string; name_en: string; name_bn: string },
        SourcingGroupOption
    >('post', SourcingGroupController.quickStore.url(), {
        code: '',
        name_en: '',
        name_bn: '',
    });

    const submit = async () => {
        try {
            const group = await http.submit();

            http.reset();
            onCreated(group);
        } catch {
            // Validation errors are held on `http.errors` and shown below.
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('sourcing.form.create_title')}</DialogTitle>
                    <DialogDescription>
                        {t('sourcing.picker.create_help')}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <FormField
                        label={t('sourcing.form.code')}
                        description={t('sourcing.form.code_help')}
                        error={http.errors.code}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                value={http.data.code}
                                onChange={(event) =>
                                    http.setData('code', event.target.value)
                                }
                                autoComplete="off"
                            />
                        )}
                    </FormField>
                    <FormField
                        label={t('sourcing.form.name_en')}
                        error={http.errors.name_en}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                value={http.data.name_en}
                                onChange={(event) =>
                                    http.setData('name_en', event.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label={t('sourcing.form.name_bn')}
                        error={http.errors.name_bn}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                value={http.data.name_bn}
                                onChange={(event) =>
                                    http.setData('name_bn', event.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <Button
                        type="button"
                        disabled={http.processing}
                        onClick={() => void submit()}
                    >
                        {t('sourcing.form.save')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
