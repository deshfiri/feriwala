/**
 * The brand images, as the server resolved them.
 *
 * Addresses only: the uploaded image when there is one and its file is present,
 * otherwise the shipped default. The browser never receives a storage path.
 */
export type Branding = {
    logo_url: string;
    favicon_url: string;
    favicon_type: string;
};
