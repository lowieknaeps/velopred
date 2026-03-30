<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Manuele incident-overrides
    |--------------------------------------------------------------------------
    |
    | Gebruik dit voor gekende valpartijen/blessures die nog niet snel genoeg
    | in de standaard dataflow zitten. Severity is 0..1 en vervalt lineair
    | over decay_days.
    |
    */
    'manual_incidents' => [
        /*
        'rider-pcs-slug' => [
            'date' => '2026-03-30', // datum van valpartij/blessure
            'severity' => 0.70,     // 0.0 .. 1.0
            'decay_days' => 21,     // lineair verval van impact
            'note' => 'Optionele context',
        ],
        */
    ],
];
