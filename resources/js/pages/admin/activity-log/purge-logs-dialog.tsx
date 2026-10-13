import { Form } from '@inertiajs/react';
import { useState } from 'react';
import ActivityLogController from '@/actions/App/Http/Controllers/Admin/ActivityLogController';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';

const controlClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none';

/**
 * Clearing the activity log (D26): everything, or one person's entries.
 *
 * Permanent, so it asks for the reason, the person's own password and an
 * explicit "I understand". The server checks every one of them again, and
 * records the clearing itself where it can never be deleted.
 */
export default function PurgeLogsDialog({
    open,
    onClose,
    person,
    personName,
}: {
    open: boolean;
    onClose: () => void;
    /** The person currently selected in the log: a public id, "system", or null. */
    person: string | null;
    personName: string | null;
}) {
    const { t } = useTranslation();
    const [scope, setScope] = useState<'everyone' | 'person'>(
        person ? 'person' : 'everyone',
    );

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('audit.purge.title')}</DialogTitle>
                    <DialogDescription>
                        {t('audit.purge.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...ActivityLogController.purge.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">
                                    {t('audit.purge.scope')}
                                </legend>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="scope"
                                        value="everyone"
                                        checked={scope === 'everyone'}
                                        onChange={() => setScope('everyone')}
                                        className="accent-brand size-4"
                                    />
                                    {t('audit.purge.everyone')}
                                </label>
                                {person && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="scope"
                                            value="person"
                                            checked={scope === 'person'}
                                            onChange={() => setScope('person')}
                                            className="accent-brand size-4"
                                        />
                                        {person === 'system'
                                            ? t('audit.purge.system')
                                            : t('audit.purge.person', {
                                                  name: personName ?? '',
                                              })}
                                    </label>
                                )}
                                {person && scope === 'person' && (
                                    <input
                                        type="hidden"
                                        name="person"
                                        value={person}
                                    />
                                )}
                                <InputError message={errors.person} />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="purge-older">
                                    {t('audit.purge.older_than')}
                                </Label>
                                <Input
                                    id="purge-older"
                                    name="older_than_days"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    step={1}
                                    className="max-w-40"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('audit.purge.older_than_help')}
                                </p>
                                <InputError message={errors.older_than_days} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="purge-reason">
                                    {t('audit.purge.reason')}
                                </Label>
                                <textarea
                                    id="purge-reason"
                                    name="reason"
                                    rows={3}
                                    minLength={10}
                                    maxLength={1000}
                                    required
                                    className={controlClass}
                                />
                                <p className="text-muted-foreground text-xs">
                                    {t('audit.purge.reason_help')}
                                </p>
                                <InputError message={errors.reason} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="purge-password">
                                    {t('audit.purge.password')}
                                </Label>
                                <Input
                                    id="purge-password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="space-y-1">
                                <label className="flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="confirm"
                                        value="1"
                                        required
                                        className="accent-brand mt-0.5 size-4"
                                    />
                                    {t('audit.purge.confirm')}
                                </label>
                                <InputError message={errors.confirm} />
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onClose}
                                >
                                    {t('common.actions.cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('audit.purge.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
