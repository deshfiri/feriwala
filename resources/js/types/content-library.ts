/**
 * Content Library shapes. Content is an ordered list of blocks — structured
 * data the page renders itself, never HTML.
 */

/** A block as the editor holds it and the server stores it. */
export type EditorBlock =
    | { key: string; type: 'text'; text: string }
    | {
          key: string;
          type: 'image';
          file_id: string;
          url: string;
          alt: string;
          caption: string;
      }
    | {
          key: string;
          type: 'video';
          /** Either an uploaded file (file_id and url) or a link. */
          file_id: string;
          url: string;
          source: 'upload' | 'link';
          caption: string;
      }
    | {
          key: string;
          type: 'link';
          url: string;
          label: string;
          description: string;
      };

/** A block as a page renders it, with addresses already resolved. */
export type PresentedBlock =
    | { type: 'text'; text: string }
    | {
          type: 'image';
          url: string;
          alt: string | null;
          caption: string | null;
      }
    | {
          type: 'video';
          url: string;
          embed_url?: string | null;
          mime_type: string | null;
          caption: string | null;
      }
    | {
          type: 'link';
          url: string;
          label: string;
          description: string | null;
      };

export type LibraryContent = {
    id: string;
    title: string;
    published_at: string;
    blocks: PresentedBlock[];
};

/** Library content on one Product's page in the admin, with what the viewer may do. */
export type ProductLibraryContent = {
    items: (LibraryContent & {
        published_by: string | null;
        products_count: number;
    })[];
    can: { publish: boolean; edit: boolean; delete: boolean };
};

export type LibraryLimits = {
    image_types: string[];
    video_types: string[];
};

export type LibraryRow = {
    id: string;
    title: string;
    block_counts: Record<string, number>;
    products_count: number;
    products: { id: string; name: string }[];
    published_at: string;
    published_by: string | null;
};
