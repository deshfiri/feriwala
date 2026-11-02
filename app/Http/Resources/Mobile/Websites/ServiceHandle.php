<?php

namespace App\Http\Resources\Mobile\Websites;

/**
 * An opaque handle for a website's domain or hosting term.
 *
 * Those two tables carry no public identifier, and the web renewal route puts
 * the database id in its URL. A mobile payload never shows a database id
 * (non-negotiable 8), so the handle is derived from the id and the application
 * key: stable, impossible to guess, and resolved only among the website's own
 * rows. Nothing is stored.
 */
final class ServiceHandle
{
    public static function for(string $kind, int $id): string
    {
        return substr(hash_hmac('sha256', $kind.':'.$id, (string) config('app.key')), 0, 26);
    }

    /**
     * The first of `$candidates` (each with an `id`) whose handle this is.
     *
     * @template T of object
     *
     * @param  iterable<T>  $candidates
     * @return T|null
     */
    public static function find(string $kind, string $handle, iterable $candidates): ?object
    {
        foreach ($candidates as $candidate) {
            if (hash_equals(self::for($kind, (int) $candidate->id), $handle)) {
                return $candidate;
            }
        }

        return null;
    }
}
