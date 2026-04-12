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

    /*
    |--------------------------------------------------------------------------
    | Manuele koersdynamiek-overrides (pech / koersverloop)
    |--------------------------------------------------------------------------
    |
    | Gebruik race-specifieke signalen wanneer uitslag alleen het koersverloop
    | niet goed weerspiegelt (lekke band, val, pech, sterke aanvalsprestatie).
    |
    | race key formaat: "{pcs_slug}:{year}"
    | rider values:
    | - form_adjustment: -1..1  (positief = sterker dan uitslag toont)
    | - incident_penalty: 0..1  (extra pech/incident-impact)
    | - date: eventdatum (default race start_date)
    | - decay_days: lineair verval (default 21)
    */
    'manual_race_dynamics' => [
        'paris-roubaix:2026' => [
            'wout-van-aert' => [
                'date' => '2026-04-12',
                'form_adjustment' => 0.42,
                'incident_penalty' => 0.06,
                'decay_days' => 24,
                'note' => 'Sterk koersverloop ondanks pechmomenten',
            ],
            'tadej-pogacar' => [
                'date' => '2026-04-12',
                'form_adjustment' => 0.28,
                'incident_penalty' => 0.04,
                'decay_days' => 21,
                'note' => 'Topniveau bevestigd in koersverloop',
            ],
            'mathieu-van-der-poel' => [
                'date' => '2026-04-12',
                'form_adjustment' => 0.18,
                'incident_penalty' => 0.14,
                'decay_days' => 21,
                'note' => 'Pech/lekke band beïnvloedde finale',
            ],
        ],
    ],
];
