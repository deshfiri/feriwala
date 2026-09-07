<?php

namespace App\Domain\Notification\Data;

/**
 * One row of the header notification centre (§33.2, D20).
 *
 * A presentation shape, not the stored notification. What the database keeps is
 * an `event` plus whatever fields that event needed — `round`, `feedback`,
 * `account_restricted` — and no two events agree on a shape. Turning that into a
 * title and a description is a display decision, so it happens once here rather
 * than in seven places in the front end.
 *
 * Both times are carried: `createdAt` is the phrase a person reads, already
 * translated server-side, and `createdAtIso` is the machine value behind it for
 * the `<time>` element. The client never formats a date, for the same reason it
 * never formats money.
 *
 * Both are nullable because the notifications table declares its timestamps
 * nullable, so a row genuinely can arrive without one. The front end omits the
 * `<time>` element entirely in that case — an unknown time is shown as nothing,
 * never as "just now", which would be a guess presented as a fact.
 */
readonly class HeaderNotification
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public ?string $href,
        public ?string $createdAt,
        public ?string $createdAtIso,
        public ?string $readAt,
    ) {}
}
