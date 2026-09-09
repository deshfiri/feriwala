import { Form } from '@inertiajs/react';
import PackageAssignmentController from '@/actions/App/Http/Controllers/Admin/PackageAssignmentController';
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

export type AssignablePackage = { slug: string; name: string };

/**
 * Giving an account a package without a sale (§8.3, §32.2).
 *
 * The consequence is stated before the button, because "assign" reads like a
 * setting and behaves like handing a business real entitlement for nothing —
 * and it replaces whatever term they are on.
 *
 * The dates are asked for rather than derived: §8.3 makes the effective date
 * something an administrator configures, and a promotion runs for its
 * promotion rather than for whatever the package's validity happens to say.
 *
 * The reason goes to the audit trail and is never shown to the account (§7.3).
 */
export default function AssignPackageDialog({
    accountId,
    packages,
    open,
    onOpenChange,
}: {
    accountId: string;
    packages: AssignablePackage[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();

    const today = new Date().toISOString().slice(0, 10);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('package.assign.title')}</DialogTitle>
                    <DialogDescription>
                        {t('package.assign.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...PackageAssignmentController.form(accountId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="assign-package">
                                    {t('package.assign.package')}
                                </Label>

                                <select
                                    id="assign-package"
                                    name="package"
                                    required
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    {packages.map((option) => (
                                        <option
                                            key={option.slug}
                                            value={option.slug}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </select>

                                <InputError message={errors.package} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="assign-starts">
                                        {t('package.assign.starts_at')}
                                    </Label>
                                    <Input
                                        id="assign-starts"
                                        name="starts_at"
                                        type="date"
                                        required
                                        defaultValue={today}
                                    />
                                    <InputError message={errors.starts_at} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="assign-expires">
                                        {t('package.assign.expires_at')}
                                    </Label>
                                    <Input
                                        id="assign-expires"
                                        name="expires_at"
                                        type="date"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {t('package.assign.expires_help')}
                                    </p>
                                    <InputError message={errors.expires_at} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="assign-reason">
                                    {t('package.assign.reason')}
                                </Label>

                                <textarea
                                    id="assign-reason"
                                    name="reason"
                                    rows={3}
                                    required
                                    className="border-input bg-background focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                />

                                <p className="text-muted-foreground text-xs">
                                    {t('package.assign.reason_help')}
                                </p>

                                <InputError message={errors.reason} />
                            </div>

                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="promotional"
                                    value="1"
                                    className="accent-brand mt-0.5"
                                />
                                <span>{t('package.assign.promotional')}</span>
                            </label>

                            <p className="bg-muted border-border rounded-md border px-3 py-2.5 text-sm">
                                {t('package.assign.warning')}
                            </p>

                            <DialogFooter className="gap-2 sm:gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => onOpenChange(false)}
                                >
                                    {t('common.actions.cancel')}
                                </Button>

                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('package.assign.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
