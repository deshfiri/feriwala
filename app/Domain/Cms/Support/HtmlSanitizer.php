<?php

namespace App\Domain\Cms\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * An allow-list HTML sanitizer for CMS rich-text fields (§4, §34's content
 * safety requirement). No new Composer dependency: PHP's own `dom` extension
 * is a core extension, not a package, and an allow-list walk over a parsed
 * DOM is a stronger guarantee than any regex/strip_tags approach — an
 * attribute cannot survive on a tag that was never in the allow-list to
 * begin with, and a tag that was allowed still has every attribute checked
 * individually.
 *
 * Deliberately narrow: CMS section copy is marketing text, not a document
 * editor's output, so the allow-list is small on purpose. Run at both write
 * time (before a section's content is saved) and defensively at render time
 * (in case a row was ever written by a path that forgot to sanitize) —
 * sanitizing twice is idempotent and cheap; not sanitizing once is the
 * failure this exists to prevent.
 */
class HtmlSanitizer
{
    /**
     * @var array<string, array<int, string>>
     */
    protected const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'strong' => [],
        'em' => [],
        'br' => [],
        'span' => [],
    ];

    protected const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Removed with their entire contents, never unwrapped — the child text
     * of a `<script>` or `<style>` element is code, not copy, and keeping it
     * as plain text on "unwrap the tag" would print raw JS/CSS on the page
     * instead of correctly discarding it outright.
     *
     * @var array<int, string>
     */
    protected const STRIPPED_WITH_CONTENTS = ['script', 'style', 'iframe', 'object', 'embed'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument;

        // Wrapped in a fragment root so a bare run of text/inline tags parses
        // as one thing; UTF-8 declared explicitly or libxml mangles non-ASCII
        // (Bangla) text.
        $wrapped = '<?xml encoding="utf-8"?><fragment>'.$html.'</fragment>';

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $fragment = $document->getElementsByTagName('fragment')->item(0);

        if ($fragment === null) {
            return '';
        }

        $this->cleanChildren($fragment);

        $output = '';

        foreach (iterator_to_array($fragment->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * A plain-text field (a heading, a label, a CTA button) — every tag
     * stripped, no allow-list needed because none is ever wanted.
     */
    public function plainText(string $value): string
    {
        return trim(strip_tags($value));
    }

    protected function cleanChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::STRIPPED_WITH_CONTENTS, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED_ATTRIBUTES)) {
                // Not on the allow-list: drop the tag but keep its text, so a
                // disallowed wrapper does not also delete real copy.
                $this->replaceWithChildren($node, $child);

                continue;
            }

            $this->stripDisallowedAttributes($child, self::ALLOWED_ATTRIBUTES[$tag]);
            $this->cleanChildren($child);
        }
    }

    /**
     * @param  array<int, string>  $allowed
     */
    protected function stripDisallowedAttributes(DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->name);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'href' && ! $this->isSafeUrl($attribute->value)) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    protected function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Site-relative — always safe, never carries a scheme to check.
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme !== '' && in_array($scheme, self::ALLOWED_URL_SCHEMES, true);
    }

    protected function replaceWithChildren(DOMNode $parent, DOMElement $child): void
    {
        while ($child->firstChild !== null) {
            $parent->insertBefore($child->firstChild, $child);
        }

        $parent->removeChild($child);
    }
}
