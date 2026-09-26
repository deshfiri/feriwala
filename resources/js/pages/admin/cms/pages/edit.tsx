import { Form, Head, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    History,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import PageController from '@/actions/App/Http/Controllers/Admin/Cms/PageController';
import PageRevisionController from '@/actions/App/Http/Controllers/Admin/Cms/PageRevisionController';
import PageSectionController from '@/actions/App/Http/Controllers/Admin/Cms/PageSectionController';
import InputError from '@/components/input-error';
import PageContainer from '@/components/page-container';
import PageHeader from '@/components/page-header';
import SectionCard from '@/components/section-card';
import StatusPill from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { CmsAdminPage, CmsAdminRevision, CmsAdminSection } from '@/types';
import SectionDialog from './section-dialog';

type Props = {
    page: CmsAdminPage;
    sections: CmsAdminSection[];
    revisions: CmsAdminRevision[];
    section_kinds: { value: string; label: string }[];
    can: {
        edit: boolean;
        publish: boolean;
        unpublish: boolean;
        delete: boolean;
    };
};

/**
 * The CMS page workspace (§4, §34, Stage 7). Everything here edits the
 * page's live, draft-side state — sections and the SEO override — except
 * Publish/Schedule/Unpublish and Restore, which are the only actions that
 * ever change what a visitor sees.
 */
export default function PageEdit({
    page,
    sections,
    revisions,
    section_kinds: sectionKinds,
    can,
}: Props) {
    const { t } = useTranslation();
    const [editingSection, setEditingSection] =
        useState<CmsAdminSection | null>(null);
    const [creatingSection, setCreatingSection] = useState(false);
    const [scheduling, setScheduling] = useState(false);

    const move = (section: CmsAdminSection, direction: -1 | 1) => {
        const order = [...sections]
            .sort((a, b) => a.sort_order - b.sort_order)
            .map((s) => s.section_key);
        const from = order.indexOf(section.section_key);
        const to = from + direction;

        if (from < 0 || to < 0 || to >= order.length) {
            return;
        }

        [order[from], order[to]] = [order[to], order[from]];

        router.post(
            PageSectionController.reorder.url(page.id),
            { order },
            { preserveScroll: true },
        );
    };

    const removeSection = (section: CmsAdminSection) => {
        if (!window.confirm(t('cms.page_edit.remove_section_confirm'))) {
            return;
        }

        router.delete(
            PageSectionController.destroy.url([page.id, section.id]),
            { preserveScroll: true },
        );
    };

    const unpublish = () => {
        if (!window.confirm(t('cms.page_edit.unpublish_confirm'))) {
            return;
        }

        router.post(
            PageController.unpublish.url(page.id),
            {},
            { preserveScroll: true },
        );
    };

    const restore = (revision: CmsAdminRevision) => {
        if (
            !window.confirm(
                t('cms.page_edit.restore_confirm', {
                    version: revision.version,
                }),
            )
        ) {
            return;
        }

        router.post(
            PageRevisionController.restore.url([page.id, revision.id]),
            {},
            { preserveScroll: true },
        );
    };

    const orderedSections = [...sections].sort(
        (a, b) => a.sort_order - b.sort_order,
    );

    return (
        <>
            <Head title={t('cms.page_edit.title', { slug: page.slug })} />

            <PageContainer width="narrow">
                <PageHeader
                    title={t('cms.page_edit.title', { slug: page.slug })}
                    description={t('cms.page_edit.description')}
                    actions={
                        <StatusPill
                            tone={page.publication_state.tone}
                            label={page.publication_state.label}
                        />
                    }
                />

                <SectionCard
                    title={t('cms.page_edit.sections_title')}
                    description={t('cms.page_edit.sections_description')}
                    actions={
                        can.edit ? (
                            <Button
                                size="sm"
                                onClick={() => setCreatingSection(true)}
                            >
                                <Plus className="size-4" />
                                {t('cms.page_edit.add_section')}
                            </Button>
                        ) : undefined
                    }
                >
                    <ul className="divide-border divide-y">
                        {orderedSections.map((section, index) => (
                            <li
                                key={section.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div className="min-w-0 space-y-0.5">
                                    <p className="text-sm font-medium">
                                        {section.section_key}
                                        <span className="text-muted-foreground ms-2 font-normal">
                                            ({section.kind})
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {section.is_enabled
                                            ? t('cms.page_edit.enabled')
                                            : t('cms.page_edit.disabled')}
                                        {' · '}
                                        {section.visible_on_desktop
                                            ? t('cms.page_edit.desktop_yes')
                                            : t('cms.page_edit.desktop_no')}
                                        {' · '}
                                        {section.visible_on_mobile
                                            ? t('cms.page_edit.mobile_yes')
                                            : t('cms.page_edit.mobile_no')}
                                    </p>
                                </div>

                                {can.edit && (
                                    <div className="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            disabled={index === 0}
                                            onClick={() => move(section, -1)}
                                        >
                                            <ArrowUp className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            disabled={
                                                index ===
                                                orderedSections.length - 1
                                            }
                                            onClick={() => move(section, 1)}
                                        >
                                            <ArrowDown className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                setEditingSection(section)
                                            }
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                removeSection(section)
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </SectionCard>

                <SectionCard
                    title={t('cms.page_edit.seo_title')}
                    description={t('cms.page_edit.seo_description')}
                >
                    <Form
                        {...PageController.updateMeta.form(page.id)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="seo-title-en">
                                            {t('cms.page_edit.seo_title_en')}
                                        </Label>
                                        <Input
                                            id="seo-title-en"
                                            name="title[en]"
                                            defaultValue={
                                                page.seo_overrides.title.en ??
                                                ''
                                            }
                                        />
                                        <InputError
                                            message={errors['title.en']}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="seo-title-bn">
                                            {t('cms.page_edit.seo_title_bn')}
                                        </Label>
                                        <Input
                                            id="seo-title-bn"
                                            name="title[bn]"
                                            defaultValue={
                                                page.seo_overrides.title.bn ??
                                                ''
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="seo-description-en">
                                            {t(
                                                'cms.page_edit.seo_description_en',
                                            )}
                                        </Label>
                                        <textarea
                                            id="seo-description-en"
                                            name="description[en]"
                                            rows={2}
                                            defaultValue={
                                                page.seo_overrides.description
                                                    .en ?? ''
                                            }
                                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="seo-description-bn">
                                            {t(
                                                'cms.page_edit.seo_description_bn',
                                            )}
                                        </Label>
                                        <textarea
                                            id="seo-description-bn"
                                            name="description[bn]"
                                            rows={2}
                                            defaultValue={
                                                page.seo_overrides.description
                                                    .bn ?? ''
                                            }
                                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="seo-canonical">
                                            {t(
                                                'cms.page_edit.seo_canonical_url',
                                            )}
                                        </Label>
                                        <Input
                                            id="seo-canonical"
                                            name="canonical_url"
                                            defaultValue={
                                                page.seo_overrides
                                                    .canonical_url ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.canonical_url}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="seo-robots">
                                            {t('cms.page_edit.seo_robots')}
                                        </Label>
                                        <Input
                                            id="seo-robots"
                                            name="robots"
                                            placeholder="index, follow"
                                            defaultValue={
                                                page.seo_overrides.robots ?? ''
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={processing || !can.edit}
                                    >
                                        {processing && <Spinner />}
                                        {t('cms.actions.save_draft')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </SectionCard>

                <SectionCard
                    title={t('cms.page_edit.publish_title')}
                    description={t('cms.page_edit.publish_description')}
                >
                    <div className="flex flex-wrap items-center gap-3">
                        {can.publish && (
                            <Form
                                {...PageController.publish.form(page.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('cms.page_edit.publish_now')}
                                    </Button>
                                )}
                            </Form>
                        )}

                        {can.publish && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setScheduling((value) => !value)}
                            >
                                {t('cms.page_edit.schedule')}
                            </Button>
                        )}

                        {can.unpublish &&
                            page.publication_state.value === 'published' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={unpublish}
                                >
                                    {t('cms.page_edit.unpublish')}
                                </Button>
                            )}
                    </div>

                    {scheduling && can.publish && (
                        <Form
                            {...PageController.publish.form(page.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setScheduling(false)}
                            className="mt-4 flex flex-wrap items-end gap-3 border-t pt-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="publish_at">
                                            {t('cms.page_edit.publish_at')}
                                        </Label>
                                        <Input
                                            id="publish_at"
                                            type="datetime-local"
                                            name="publish_at"
                                            required
                                        />
                                        <InputError
                                            message={errors.publish_at}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="reason">
                                            {t('cms.page_edit.reason')}
                                        </Label>
                                        <Input id="reason" name="reason" />
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('cms.page_edit.schedule_confirm')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </SectionCard>

                <SectionCard
                    title={t('cms.page_edit.history_title')}
                    description={t('cms.page_edit.history_description')}
                >
                    {revisions.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {t('cms.page_edit.no_revisions')}
                        </p>
                    ) : (
                        <ul className="divide-border divide-y">
                            {revisions.map((revision) => (
                                <li
                                    key={revision.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="flex items-center gap-2 text-sm font-medium">
                                            <History className="text-muted-foreground size-4" />
                                            {t('cms.page_edit.version', {
                                                version: revision.version,
                                            })}
                                            <StatusPill
                                                tone={
                                                    revision.publication_state
                                                        .tone
                                                }
                                                label={
                                                    revision.publication_state
                                                        .label
                                                }
                                            />
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {revision.published_at ??
                                                t('cms.page_edit.not_yet_live')}
                                            {revision.created_by &&
                                                ` · ${revision.created_by}`}
                                            {revision.reason &&
                                                ` · ${revision.reason}`}
                                            {revision.restored_from_version !==
                                                null &&
                                                ` · ${t(
                                                    'cms.page_edit.restored_from',
                                                    {
                                                        version:
                                                            revision.restored_from_version,
                                                    },
                                                )}`}
                                        </p>
                                    </div>

                                    {can.publish &&
                                        revision.publication_state.value !==
                                            'published' && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    restore(revision)
                                                }
                                            >
                                                <RotateCcw className="size-4" />
                                                {t('cms.page_edit.restore')}
                                            </Button>
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </PageContainer>

            {(creatingSection || editingSection !== null) && (
                <SectionDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setCreatingSection(false);
                            setEditingSection(null);
                        }
                    }}
                    pageId={page.id}
                    section={editingSection}
                    sectionKinds={sectionKinds}
                />
            )}
        </>
    );
}
