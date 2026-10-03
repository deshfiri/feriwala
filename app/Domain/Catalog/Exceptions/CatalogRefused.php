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

    /**
     * A product that has moved through its lifecycle even once is part of the
     * record, and its history cannot be deleted from under it.
     */
    public static function productHasHistory(): self
    {
        return new self(
            'This draft has already been through review, and its status history stays on record. '
            .'Archive it instead of deleting it.'
        );
    }

    public static function notLifecycleStatus(string $status): self
    {
        return new self("{$status} is a sales-channel setting, not a lifecycle status.");
    }

    public static function relatedToItself(): self
    {
        return new self('A product cannot be related to itself.');
    }

    public static function tooManyRelated(int $max): self
    {
        return new self("A product recommends up to {$max} related products.");
    }

    public static function channelUnchanged(string $channel, bool $enable): self
    {
        return new self("{$channel} is already switched ".($enable ? 'on' : 'off').' for this product.');
    }

    public static function reasonRequired(string $status): self
    {
        return new self("Say why the product is being moved to {$status}. Somebody will ask.");
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function cannotActivate(array $missing): self
    {
        return new self('This product cannot be activated yet: '.implode('; ', $missing).'.');
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

    public static function variantNotDraft(): self
    {
        return new self(
            'A variation can only be deleted while its product is a draft. Switch it off instead — '
            .'that stops it being offered without erasing what the catalogue said about it.'
        );
    }

    /**
     * Two values of one attribute on one variant — a shirt that is both M and L.
     */
    public static function attributeRepeated(string $attribute): self
    {
        return new self("A variation takes one value of {$attribute}, not several.");
    }

    /**
     * A variant whose attributes differ from its siblings'.
     *
     * A product sold by Size and Colour cannot also have a variant chosen by
     * Material alone: a storefront would have no way to offer it.
     */
    public static function inconsistentAttributes(string $expected): self
    {
        return new self("Every variation of this product is chosen by {$expected}. Pick one value of each.");
    }

    public static function attributeInUse(int $count): self
    {
        return new self(sprintf(
            'This is used by %d variation%s. Remove those first; a value in use cannot be taken away from under them.',
            $count,
            $count === 1 ? '' : 's',
        ));
    }

    /**
     * An upload whose bytes are neither an accepted image nor an accepted video.
     */
    public static function mediaTypeNotAccepted(string $mime): self
    {
        return new self(sprintf(
            'A %s cannot be used as product media. Images: JPEG, PNG or WebP. Videos: MP4 or WebM.',
            $mime === '' ? 'file of that type' : $mime,
        ));
    }

    public static function mediaTooLarge(string $type, int $bytes, int $maxBytes): self
    {
        return new self(sprintf(
            'That %s is %s. Product %ss go up to %s.',
            $type,
            self::megabytes($bytes),
            $type,
            self::megabytes($maxBytes),
        ));
    }

    public static function tooMuchMedia(int $max): self
    {
        return new self("A product holds up to {$max} images and videos. Remove one before adding another.");
    }

    /**
     * A variation named for a picture that belongs to a different product.
     */
    public static function variantOfAnotherProduct(): self
    {
        return new self('That variation belongs to a different product.');
    }

    public static function tierQuantityTooLow(): self
    {
        return new self('A quantity tier starts at 2 units or more. One unit is the base price.');
    }

    public static function tierQuantityRepeated(int $quantity): self
    {
        return new self("There are two tiers starting at {$quantity} units. Each band needs its own starting quantity.");
    }

    /**
     * A band that charges more per unit than the band before it.
     */
    public static function tierPriceRises(int $quantity): self
    {
        return new self(
            "The tier from {$quantity} units costs more per unit than the price below it. "
            .'Buying more should never cost more per unit.'
        );
    }

    public static function tierAboveMaximumOrder(int $quantity, int $maximum): self
    {
        return new self(
            "The tier from {$quantity} units can never apply: one order carries at most {$maximum}. "
            .'Lower the tier or raise the maximum order quantity.'
        );
    }

    public static function tooManyTiers(int $max): self
    {
        return new self("A price table holds up to {$max} tiers.");
    }

    protected static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1).' MB';
    }

    public static function duplicateCombination(): self
    {
        return new self('A variation with that combination of attributes already exists on this product.');
    }

    public static function noAttributes(): self
    {
        return new self('A variation needs at least one attribute value.');
    }

    /**
     * Refused before a single combination is worked out, so a careless pick of
     * every value of every attribute cannot build thousands of rows.
     */
    public static function tooManyCombinations(int $count, int $max): self
    {
        return new self("Those values make {$count} combinations. Build up to {$max} at a time.");
    }

    public static function noFreeSku(string $base): self
    {
        return new self("No free BPC could be made from {$base}. Add this variation by hand with a BPC of your choosing.");
    }
}
