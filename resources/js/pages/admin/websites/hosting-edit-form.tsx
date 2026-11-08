import { Form } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteController from '@/actions/App/Http/Controllers/Admin/WebsiteController';
import { toInstant, toLocalInput } from '@/lib/datetime-local';
import type { WebsiteHostingRow } from '@/types/website';

/**
 * Correcting one hosting term: its plan, its provider (which stays internal) and
 * when it runs out. Closed by default; the fee is not here, because it is a
 * financial record.
 */
export default function HostingEditForm({
    websiteId,
    hosting,
}: {
    websiteId: string;
    hosting: WebsiteHostingRow;
}) {
    const { t } = useTranslation();

    return (
        <details className="mt-2">
            <summary className="text-primary cursor-pointer text-sm font-medium">
                {t('website.edit.edit')}
            </summary>

            <Form
                {...WebsiteController.updateHosting.form({
                    website: websiteId,
                    hosting: hosting.id,
                })}
                options={{ preserveScroll: true }}
                transform={(data) => ({
                    ...data,
                    expires_at: toInstant(data.expires_at),
                })}
                className="mt-3 space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <FormField
                            label={t('website.admin.hosting_plan')}
                            error={errors.plan}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="plan"
                                    defaultValue={hosting.plan}
                                    maxLength={120}
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('website.admin.hosting_provider')}
                            error={errors.provider}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="provider"
                                    defaultValue={hosting.provider ?? ''}
                                    maxLength={120}
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('website.edit.expires_at')}
                            error={errors.expires_at}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="expires_at"
                                    type="datetime-local"
                                    defaultValue={toLocalInput(
                                        hosting.expires_at,
                                    )}
                                    required
                                />
                            )}
                        </FormField>

                        <input type="hidden" name="auto_renew" value="0" />
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="auto_renew"
                                value="1"
                                defaultChecked={hosting.auto_renew}
                            />
                            {t('website.edit.auto_renew')}
                        </label>

                        <Button type="submit" size="sm" disabled={processing}>
                            {processing && <Spinner />}
                            {t('website.edit.save_hosting')}
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}
