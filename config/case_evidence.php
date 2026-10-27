<?php

/*
 * Evidence files for return, exchange and damage cases (D35).
 *
 * Written through the shared managed-storage abstraction as private files, so
 * the type is read from the file's own bytes and the disk — local or R2 — is
 * whichever is in use. The size limit is the smaller of this and what the server
 * itself will accept in one upload.
 */
return [
    // The largest one file, in bytes. 8 MB by default.
    'max_bytes' => (int) env('CASE_EVIDENCE_MAX_BYTES', 8 * 1024 * 1024),

    // The most evidence a draft request, or one case, may hold.
    'max_per_case' => (int) env('CASE_EVIDENCE_MAX_PER_CASE', 10),

    // How long a signed preview or download link works, in minutes.
    'link_minutes' => (int) env('CASE_EVIDENCE_LINK_MINUTES', 10),

    // What is accepted, by the type found in the file's own bytes.
    'mime_types' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ],
];
