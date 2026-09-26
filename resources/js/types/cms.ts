/**
 * The public landing page's props (§4, §34). Every string here is already
 * resolved to the current locale server-side
 * (App\Domain\Cms\Support\LocalizedContentResolver) — a section renderer
 * never sees a `{en, bn}` object and never picks a locale itself.
 */

export type CmsCta = {
    label: string;
    /** A resolved href — a route already checked to exist, or a safe path. */
    href: string;
};

/**
 * A media reference, already frozen into immutable metadata at publish
 * time and locale-resolved (`alt` is a plain string, not `{en, bn}`) —
 * Stage 7 addendum. Never a storage path, always the CMS's own public
 * URL. `width`/`height` may be null for a file whose dimensions could not
 * be read.
 */
export type CmsMedia = {
    public_id: string;
    url: string;
    width: number | null;
    height: number | null;
    mime_type: string;
    alt: string;
};

/** Mirrors App\Domain\Cms\Support\SectionContentValidator's `media_position` rule. */
export type CmsMediaPosition = 'left' | 'right' | 'background' | null;

/** Mirrors App\Domain\Cms\Support\SectionContentValidator's `media_fit` rule. */
export type CmsMediaFit = 'cover' | 'contain' | null;

export type CmsSection = {
    key: string;
    kind: string;
    variant: string | null;
    visible_on_desktop: boolean;
    visible_on_mobile: boolean;
    /** Shape depends on `kind` — narrowed by each section renderer. */
    content: Record<string, unknown>;
};

export type CmsSeo = {
    title: string;
    description?: string | null;
    canonical_url?: string | null;
    og_image_url?: string | null;
    robots?: string;
    organization_name?: string | null;
    organization_url?: string | null;
    organization_logo_url?: string | null;
};

export type CmsMenuItem = {
    key: string;
    label: string;
    href: string;
    target: 'self' | 'blank';
    children: CmsMenuItem[];
};

export type CmsMenus = {
    header: CmsMenuItem[];
    footer: CmsMenuItem[];
    legal: CmsMenuItem[];
};

export type CmsPackagePreview = {
    key: string;
    name: string;
    short_description: string | null;
    fee: { currency: string; amount: string; formatted: string };
    validity_days: number | null;
};
