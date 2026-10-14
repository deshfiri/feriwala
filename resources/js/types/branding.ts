/**
 * The accent colour an administrator chose, with the label colours the server
 * picked for contrast: `on` for text on the accent itself, `lifted_on` for
 * text on the lighter accent dark mode uses. All `#rrggbb`.
 */
export type BrandingAccent = {
    color: string;
    on: string;
    lifted_on: string;
};

/**
 * The brand images, as the server resolved them.
 *
 * Addresses only: the uploaded image when there is one and its file is present,
 * otherwise the shipped default. The browser never receives a storage path.
 * `accent` is null while the shipped accent is in use.
 */
export type Branding = {
    logo_url: string;
    favicon_url: string;
    favicon_type: string;
    accent: BrandingAccent | null;
    /** The button colour, in the same shape as `accent`; null while buttons follow the accent. */
    button: BrandingAccent | null;
    /** The current navigation link colour, in the same shape as `accent`; null while it follows the accent. */
    nav: BrandingAccent | null;
    /** The light-theme page background `#rrggbb`; null while it is white. */
    background: string | null;
};
