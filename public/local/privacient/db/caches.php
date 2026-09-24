<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Company branding (name, colour, logo) fetched from the Privacient console
    // for the video player's intro slate. Keyed by IOMAD company id. Freshness
    // is managed per entry by \local_privacient\branding (10 min, or 1 min
    // after a failed fetch), so no definition-level TTL is set here.
    'branding' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => true,
        'staticaccelerationsize' => 20,
    ],
    // A video activity's running time, read from its file's MP4 header by
    // \local_privacient\video. Keyed by course module id. The hour's TTL only
    // bounds staleness if a file is ever replaced in place.
    'videoduration' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 3600,
    ],
];
