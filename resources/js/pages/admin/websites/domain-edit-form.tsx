import { Form } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteController from '@/actions/App/Http/Controllers/Admin/WebsiteController';
import { toInstant, toLocalInput } from '@/lib/datetime-local';
import type { WebsiteDomainRow } from '@/types/website';

/**
 * Correcting one registered domain: its name, registrar, term, and whether it is
 * the website's address.
 *
 * Closed by default, because most visits are to read. Renaming says what it
 * costs before it happens — the new name starts its proof of ownership again and
 * leaves the storefront until it is verified and activated — and the server does
 * exactly that, so the sentence is a warning and never a promise the screen
 * keeps.
 */
export default function DomainEditForm({
    websiteId,
    domain,
    isPrimary,
}: {
    websiteId: string;
    domain: WebsiteDomainRow;
    /** Whether this name is the website's address now. */
    isPrimary: boolean;
}) {
    const { t } = useTranslation();

    return (
        <details className="mt-2">
            <summary className="text-primary cursor-pointer text-sm font-medium">
                {t('website.edit.edit')}
            </summary>

            <Form
                {...WebsiteController.updateDomain.form({
                    website: websiteId,
                    domain: domain.id,
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
                            label={t('website.admin.domain_name')}
                            error={errors.domain}
                            hint={t('website.edit.domain_warning')}
                            required
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="domain"
                                    defaultValue={domain.domain}
                                    maxLength={253}
                                    required
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('website.admin.domain_registrar')}
                            error={errors.registrar}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="registrar"
                                    defaultValue={domain.registrar ?? ''}
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
                                        domain.expires_at,
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
                                defaultChecked={domain.auto_renew}
                            />
                            {t('website.edit.auto_renew')}
                        </label>

                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="make_primary"
                                value="1"
                                defaultChecked={isPrimary}
                            />
                            {t('website.admin.domain_primary')}
                        </label>

                        <Button type="submit" size="sm" disabled={processing}>
                            {processing && <Spinner />}
                            {t('website.edit.save_domain')}
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}
