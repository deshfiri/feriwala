<?php

/*
 * External store connections (D29).
 */
return [
    /*
     * The queue store syncs and notifications are processed on. Calls to a
     * partner's store never run inside a page request.
     */
    'queue' => env('STORES_QUEUE', 'default'),

    /*
     * Consecutive failed syncs before a connection reads as failing. One
     * timeout is weather; three in a row is something the partner should see.
     */
    'failing_after' => 3,

    /*
     * The most pages one sync walks in a pass. A store with more catches up
     * on the next run rather than holding a worker for minutes.
     */
    'max_pages_per_pass' => 20,

    /*
     * How far back the change pass reaches on its first run, and how much it
     * overlaps the last one, so an order changed at the boundary is not missed.
     */
    'initial_change_window_hours' => 24,
    'change_overlap_minutes' => 10,

    'webhook_rate_limit' => (int) env('STORES_WEBHOOK_RATE_LIMIT', 240),
];
