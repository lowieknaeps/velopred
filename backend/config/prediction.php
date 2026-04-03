<?php

return [
    'skip_pcs_signals' => (bool) env('PREDICTION_SKIP_PCS_SIGNALS', false),

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
        'mads-pedersen' => [
            'date' => '2026-03-24',
            'severity' => 0.60,
            'decay_days' => 32,
            'note' => 'Recente terugkeer na blessure/val',
        ],
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
