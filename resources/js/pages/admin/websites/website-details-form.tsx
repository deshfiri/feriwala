import { Form } from '@inertiajs/react';
import FormField from '@/components/forms/form-field';
import Textarea from '@/components/forms/textarea';
import SectionCard from '@/components/section-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import WebsiteController from '@/actions/App/Http/Controllers/Admin/WebsiteController';
import { toInstant, toLocalInput } from '@/lib/datetime-local';
import type { WebsiteDetail } from '@/types/website';

/**
 * The website's own domain and term, for the staff who run the platform.
 *
 * Set once when the website was stood up and, until this, impossible to correct:
 * a misspelt domain, a term extended by phone, a maintenance note left behind.
 * The domain is the one address a person types. The platform's own generated
 * subdomain label, and the address the partner first asked for, are internal —
 * the storefront keys on the first and staff read the second on the domain card
 * — so neither is a field here. The server holds the domain to the same rules as
 * ever (a real name, held by no other website) and shows what it refuses beside
 * the field; the dates are typed in the browser's own time and sent as instants.
 * Fees and charges are not here: they are financial records.
 */
export default function WebsiteDetailsForm({
    website,
}: {
    website: WebsiteDetail;
}) {
    const { t } = useTranslation();

    return (
        <SectionCard
            title={t('website.edit.details_title')}
            description={t('website.edit.details_hint')}
        >
            <Form
                {...WebsiteController.updateDetails.form(website.id)}
                options={{ preserveScroll: true }}
                transform={(data) => ({
                    ...data,
                    expires_at: toInstant(data.expires_at),
                    grace_ends_at: toInstant(data.grace_ends_at),
                })}
                className="grid gap-4 sm:grid-cols-2"
            >
                {({ processing, errors }) => (
                    <>
                        <FormField
                            label={t('website.edit.domain')}
                            error={errors.domain}
                            hint={t('website.edit.domain_hint')}
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="domain"
                                    defaultValue={website.domain ?? ''}
                                    maxLength={253}
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('website.edit.expires_at')}
                            error={errors.expires_at}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="expires_at"
                                    type="datetime-local"
                                    defaultValue={toLocalInput(
                                        website.lifecycle.expires_at,
                                    )}
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('website.edit.grace_ends_at')}
                            error={errors.grace_ends_at}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    name="grace_ends_at"
                                    type="datetime-local"
                                    defaultValue={toLocalInput(
                                        website.lifecycle.grace_ends_at,
                                    )}
                                />
                            )}
                        </FormField>

                        <FormField
                            label={t('website.edit.maintenance_message')}
                            error={errors.maintenance_message}
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <Textarea
                                    {...field}
                                    name="maintenance_message"
                                    defaultValue={
                                        website.lifecycle.maintenance_message ??
                                        ''
                                    }
                                    maxLength={500}
                                    rows={3}
                                />
                            )}
                        </FormField>

                        <div className="sm:col-span-2">
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('website.edit.save_details')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </SectionCard>
    );
}
