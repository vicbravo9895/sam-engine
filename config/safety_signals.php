<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical behavior labels (PascalCase)
    |--------------------------------------------------------------------------
    | Lista de labels conocidos para normalizar variantes de casing (ej. noSeatbelt → NoSeatbelt).
    | Incluye todos los tipos que pueden llegar por el safety stream de Samsara.
    */
    'canonical_labels' => [
        'Braking',
        'Crash',
        'Drowsy',
        'EdgeRailroadCrossingViolation',
        'ForwardCollisionWarning',
        'GenericDistraction',
        'HarshTurn',
        'MaxSpeed',
        'MobileUsage',
        'NoSeatbelt',
        'ObstructedCamera',
        'Passenger',
        'RollingStop',
        'SevereSpeeding',
        'Collision',
        'NearCollision',
        'NearCollison',
        'NearPedestrianCollision',
        'RearCollisionWarning',
        'HeavySpeeding',
        'HighSpeedSuddenDisconnect',
        'Acceleration',
        'Speeding',
        'ModerateSpeeding',
        'FollowingDistance',
        'FollowingDistanceSevere',
        'RanRedLight',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default labels that trigger proactive notification (from safety stream)
    |--------------------------------------------------------------------------
    | Cuando un nuevo SafetySignal tiene primary_behavior_label en esta lista
    | (tras normalizar casing), se crea un Alert y se encola el pipeline de IA.
    | Cada empresa puede sobrescribir via ai_config.safety_stream_notify.labels.
    */
    'default_notify_labels' => [
        'Crash',
        'ForwardCollisionWarning',
        'SevereSpeeding',
    ],
];
