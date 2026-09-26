/**
 * Props for the admin CMS workspace (§4, §34, Stage 7). Distinct from
 * `cms.ts`, which types the *public* reader's already-locale-resolved
 * output — every localized field here is still `{en, bn}`, exactly as
 * SectionContentValidator stores it, because this is what an editor writes.
 */

export type CmsAdminStatus = {
    value: string;
    label: string;
    tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
};

export type CmsAdminPageSummary = {
    id: string;
    slug: string;
    page_type: string;
    publication_state: CmsAdminStatus;
    scheduled_publish_at: string | null;
};

export type CmsAdminSection = {
    id: string;
    section_key: string;
    kind: string;
    sort_order: number;
    is_enabled: boolean;
    visible_on_desktop: boolean;
    visible_on_mobile: boolean;
    variant: string | null;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    content: Record<string, any>;
};

export type CmsAdminRevision = {
    id: string;
    version: number;
    publication_state: CmsAdminStatus;
    published_at: string | null;
    created_by: string | null;
    reason: string | null;
    restored_from_version: number | null;
    content: {
        sections: CmsAdminSection[];
    };
};

export type CmsAdminSeoOverride = {
    title: { en: string | null; bn: string | null };
    description: { en: string | null; bn: string | null };
    canonical_url: string | null;
    og_image_url: string | null;
    robots: string | null;
};

export type CmsAdminPage = {
    id: string;
    slug: string;
    page_type: string;
    publication_state: CmsAdminStatus;
    scheduled_publish_at: string | null;
    seo_overrides: CmsAdminSeoOverride;
};

export type CmsAdminMenuItem = {
    id: string;
    parent_id: string | null;
    label_en: string;
    label_bn: string | null;
    route_name: string | null;
    external_url: string | null;
    href: string | null;
    sort_order: number;
    is_enabled: boolean;
    link_target: 'self' | 'blank';
};

export type CmsAdminMenu = {
    location: 'header' | 'footer' | 'legal';
    id: string | null;
    items: CmsAdminMenuItem[];
};

export type CmsAdminRedirect = {
    id: string;
    from_path: string;
    to_path: string;
    status_code: number;
    is_enabled: boolean;
    created_by: string | null;
};

export type CmsAdminMedia = {
    id: string;
    url: string;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    alt_text_en: string | null;
    alt_text_bn: string | null;
    attribution: string | null;
    has_required_alt_text: boolean;
    uploaded_by: string | null;
};

export type CmsAdminSeoSetting = {
    locale: string;
    default_title: string | null;
    default_description: string | null;
    default_og_image_path: string | null;
    organization_name: string | null;
    organization_logo_path: string | null;
    organization_url: string | null;
    robots_default: string;
    twitter_handle: string | null;
};
