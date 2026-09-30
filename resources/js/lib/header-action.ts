/**
 * The square, bordered icon buttons in a shell header: the rail toggle,
 * language, appearance and notifications.
 *
 * One definition shared by the ERP/Admin header and the Supplier header, so
 * the two portals cannot drift apart by a pixel. Pass it through `cn()` after
 * a `Button`'s own classes, so it wins over the ghost variant and the
 * primitive's default size.
 */
export const headerActionClasses =
    'bg-card text-muted-foreground hover:bg-surface-hover hover:text-foreground hover:border-input relative size-10 rounded-lg border transition-colors';
