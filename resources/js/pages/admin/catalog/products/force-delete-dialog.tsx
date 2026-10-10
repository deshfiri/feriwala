import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ProductTrashController from '@/actions/App/Http/Controllers/Admin/ProductTrashController';
import AlertError from '@/components/alert-error';
import FormField from '@/components/forms/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import type { TrashedProductRow } from '@/types';

type ImpactGroup = 'removed' | 'deactivated' | 'preserved';

type Impact = {
    name: string;
    sku: string;
    impact: Record<ImpactGroup, Record<string, number>> & {
        blockers: string[];
    };
};

type Props = {
    row: TrashedProductRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

const GROUPS: ImpactGroup[] = ['removed', 'deactivated', 'preserved'];

/**
 * Super Admin Force Delete of a trashed Product that has history behind it.
 *
 * Shows what will be removed, what will be switched off and what is kept before
 * anything can be submitted, and asks for the reason, the Product's BPC or exact
 * name, and the Super Admin's password. Work still in flight is shown as a
 * blocker and disables the button; the server refuses it as well.
 */
export default function ForceDeleteDialog({ row, open, onOpenChange }: Props) {
    const { t } = useTranslation();
    const [data, setData] = useState<Impact | null>(null);
    const [loadError, setLoadError] = useState(false);
    const [reason, setReason] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        let active = true;
        setData(null);
        setLoadError(false);

        fetch(ProductTrashController.forceDeleteImpact.url(row.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('impact');
                }

                return response.json() as Promise<Impact>;
            })
            .then((json) => active && setData(json))
            .catch(() => active && setLoadError(true));

        return () => {
            active = false;
        };
    }, [open, row.id]);

    const blockers = data?.impact.blockers ?? [];
    const confirmed =
        confirmation !== '' &&
        (confirmation === row.sku || confirmation === row.name);
    const ready =
        data !== null &&
        blockers.length === 0 &&
        confirmed &&
        reason.trim().length >= 10 &&
        password !== '';

    const submit = () => {
        setProcessing(true);
        setErrors([]);

        router.delete(ProductTrashController.forceDestroy.url(row.id), {
            data: { reason, confirmation, password },
            preserveScroll: true,
            onSuccess: () => {
                setReason('');
                setConfirmation('');
                setPassword('');
                onOpenChange(false);
            },
            onError: (bag) => setErrors(Object.values(bag)),
            onFinish: () => {
                setPassword('');
                setProcessing(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!processing) {
                    onOpenChange(next);
                }
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {t('catalog.products.force_delete.title', {
                            name: row.name,
                        })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('catalog.products.force_delete.description')}
                    </DialogDescription>
                </DialogHeader>

                {loadError && (
                    <AlertError
                        errors={[
                            t('catalog.products.force_delete.load_failed'),
                        ]}
                    />
                )}

                {data === null && !loadError && (
                    <p
                        className="text-muted-foreground animate-pulse text-sm"
                        role="status"
                    >
                        {t('catalog.products.force_delete.loading')}
                    </p>
                )}

                {data !== null && (
                    <div className="space-y-3 text-sm">
                        {blockers.length > 0 && (
                            <AlertError errors={blockers} />
                        )}

                        {GROUPS.map((group) => {
                            const lines = Object.entries(
                                data.impact[group],
                            ).filter(([, count]) => count > 0);

                            if (lines.length === 0) {
                                return null;
                            }

                            return (
                                <section key={group}>
                                    <h3 className="font-medium">
                                        {t(
                                            `catalog.products.force_delete.groups.${group}`,
                                        )}
                                    </h3>
                                    <ul className="text-muted-foreground list-disc space-y-0.5 pl-5">
                                        {lines.map(([key, count]) => (
                                            <li key={key}>
                                                {t(
                                                    `catalog.products.force_delete.items.${group}.${key}`,
                                                    { count },
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            );
                        })}
                    </div>
                )}

                {errors.length > 0 && <AlertError errors={errors} />}

                <FormField
                    label={t('catalog.products.force_delete.reason_label')}
                    hint={t('catalog.products.force_delete.reason_help')}
                    required
                >
                    {(field) => (
                        <textarea
                            {...field}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={3}
                            maxLength={1000}
                            className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                        />
                    )}
                </FormField>

                <FormField
                    label={t('catalog.products.force_delete.confirm_label', {
                        sku: row.sku,
                    })}
                    hint={t('catalog.products.force_delete.confirm_help', {
                        name: row.name,
                    })}
                    required
                >
                    {(field) => (
                        <Input
                            {...field}
                            value={confirmation}
                            onChange={(event) =>
                                setConfirmation(event.target.value)
                            }
                            autoComplete="off"
                        />
                    )}
                </FormField>

                <FormField
                    label={t('catalog.products.force_delete.password_label')}
                    required
                >
                    {(field) => (
                        <Input
                            {...field}
                            type="password"
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            autoComplete="current-password"
                        />
                    )}
                </FormField>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing || !ready}
                        onClick={submit}
                    >
                        {t('catalog.products.force_delete.action')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
