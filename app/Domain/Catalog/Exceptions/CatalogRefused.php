<?php

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * A catalogue change that must not be made (§11, §12).
 *
 * Every message is written to be shown to the administrator who tried, because
 * each of these is something a person can act on rather than a fault: move the
 * subcategory, empty the category, archive instead of deleting.
 */
class CatalogRefused extends RuntimeException
{
    public static function tooDeep(int $maxDepth): self
    {
        return new self(sprintf(
            'Categories go %d level%s deep. A subcategory cannot have subcategories of its own.',
            $maxDepth,
            $maxDepth === 1 ? '' : 's',
        ));
    }

    /**
     * A category made its own ancestor.
     *
     * Left unchecked this produces a cycle, and every walk of the tree —
     * rendering a menu, resolving availability, listing descendants — runs
     * until it exhausts memory.
     */
    public static function wouldCycle(): self
    {
        return new self('A category cannot be moved inside itself or one of its own subcategories.');
    }

    public static function categoryHasChildren(int $count): self
    {
        return new self(sprintf(
            'This category still has %d subcategor%s. Move or remove them first.',
            $count,
            $count === 1 ? 'y' : 'ies',
        ));
    }

    public static function categoryHasProducts(int $count): self
    {
        return new self(sprintf(
            'This category still holds %d product%s. Move them, or switch the category off instead of deleting it.',
            $count,
            $count === 1 ? '' : 's',
        ));
    }

    public static function brandHasProducts(int $count): self
    {
        return new self(sprintf(
            'This brand is on %d product%s. Switch it off instead of deleting it.',
            $count,
            $count === 1 ? '' : 's',
        ));
    }

    /**
     * A product cannot be removed once anything downstream has quoted it.
     *
     * An order line, an invoice and a ledger entry all name what was bought.
     * Deleting the product would leave those answering "a product that no
     * longer exists", which is the one thing a financial record may never say.
     */
    public static function productIsReferenced(string $by): self
    {
        return new self(
            "This product cannot be deleted because {$by} refer to it. Archive it instead — "
            .'that stops it being sold without rewriting what was already bought.'
        );
    }

    /**
     * Only a draft is ever removed outright.
     *
     * A product that has been through review or offered to anybody is part of
     * the record of what the catalogue said, and archiving is what retires it.
     */
    public static function productNotDraft(): self
    {
        return new self(
            'Only a draft product can be deleted. Archive it instead — that stops it being offered '
            .'without erasing what the catalogue said about it.'
        );
    }

    public static function variantIsReferenced(string $by): self
    {
        return new self(
            "This variation cannot be deleted because {$by} refer to it. Switch it off instead."
        );
    }

    /**
     * An upload whose bytes are not one of the formats a storefront renders.
     *
     * The type is read from the file itself, not from what the browser claimed,
     * so this message describes what was actually sent.
     */
    public static function imageTypeNotAccepted(string $mime): self
    {
        return new self(sprintf(
            'A %s cannot be used as a catalogue image. Accepted formats: JPEG, PNG and WebP.',
            $mime === '' ? 'file of that type' : $mime,
        ));
    }

    public static function imageTooLarge(int $bytes, int $maxBytes): self
    {
        return new self(sprintf(
            'That image is %d KB. Catalogue images go up to %d KB.',
            (int) round($bytes / 1024),
            (int) round($maxBytes / 1024),
        ));
    }

    public static function duplicateCombination(): self
    {
        return new self('A variation with that combination of attributes already exists on this product.');
    }

    public static function noAttributes(): self
    {
        return new self('A variation needs at least one attribute value.');
    }
}
