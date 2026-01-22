<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Traductor de etiquetas de comportamiento de Samsara.
 * 
 * Traduce los behavior labels del Safety Events Stream API de Samsara
 * al español con descripciones detalladas para uso en la UI y reportes.
 * 
 * @see https://developers.samsara.com/reference/getsafetyeventsv2stream
 */
class BehaviorLabelTranslator
{
    /**
     * Traducciones de behavior labels al español.
     * 
     * Estructura: 'label_en' => [
     *     'name' => 'Nombre corto en español',
     *     'description' => 'Descripción detallada del evento',
     *     'category' => 'Categoría del evento',
     *     'severity' => 'info|warning|critical',
     * ]
     */
    public const TRANSLATIONS = [
        // === EVENTOS DE ACELERACIÓN Y FRENADO ===
        'Acceleration' => [
            'name' => 'Aceleración brusca',
            'description' => 'El conductor aceleró de forma agresiva o repentina',
            'category' => 'Conducción',
            'severity' => 'warning',
        ],
        'Braking' => [
            'name' => 'Frenado brusco',
            'description' => 'El conductor frenó de forma agresiva o repentina',
            'category' => 'Conducción',
            'severity' => 'warning',
        ],
        'HarshTurn' => [
            'name' => 'Giro brusco',
            'description' => 'El conductor realizó un giro de forma agresiva o a alta velocidad',
            'category' => 'Conducción',
            'severity' => 'warning',
        ],

        // === EVENTOS DE VELOCIDAD ===
        'Speeding' => [
            'name' => 'Exceso de velocidad',
            'description' => 'El vehículo excedió el límite de velocidad permitido',
            'category' => 'Velocidad',
            'severity' => 'warning',
        ],
        'LightSpeeding' => [
            'name' => 'Exceso de velocidad leve',
            'description' => 'El vehículo excedió ligeramente el límite de velocidad (1-10 km/h sobre el límite)',
            'category' => 'Velocidad',
            'severity' => 'info',
        ],
        'ModerateSpeeding' => [
            'name' => 'Exceso de velocidad moderado',
            'description' => 'El vehículo excedió moderadamente el límite de velocidad (10-20 km/h sobre el límite)',
            'category' => 'Velocidad',
            'severity' => 'warning',
        ],
        'HeavySpeeding' => [
            'name' => 'Exceso de velocidad grave',
            'description' => 'El vehículo excedió significativamente el límite de velocidad (20-30 km/h sobre el límite)',
            'category' => 'Velocidad',
            'severity' => 'critical',
        ],
        'SevereSpeeding' => [
            'name' => 'Exceso de velocidad severo',
            'description' => 'El vehículo excedió peligrosamente el límite de velocidad (más de 30 km/h sobre el límite)',
            'category' => 'Velocidad',
            'severity' => 'critical',
        ],
        'MaxSpeed' => [
            'name' => 'Velocidad máxima excedida',
            'description' => 'El vehículo excedió la velocidad máxima configurada para la flota',
            'category' => 'Velocidad',
            'severity' => 'critical',
        ],

        // === COLISIONES Y CASI COLISIONES ===
        'Crash' => [
            'name' => 'Colisión detectada',
            'description' => 'Se detectó un impacto o colisión del vehículo',
            'category' => 'Colisión',
            'severity' => 'critical',
        ],
        'NearCollison' => [  // Nota: typo intencional de Samsara API
            'name' => 'Casi colisión',
            'description' => 'El vehículo estuvo muy cerca de colisionar con otro objeto o vehículo',
            'category' => 'Colisión',
            'severity' => 'critical',
        ],
        'NearCollision' => [  // Versión corregida por si acaso
            'name' => 'Casi colisión',
            'description' => 'El vehículo estuvo muy cerca de colisionar con otro objeto o vehículo',
            'category' => 'Colisión',
            'severity' => 'critical',
        ],
        'NearPedestrianCollision' => [
            'name' => 'Casi colisión con peatón',
            'description' => 'El vehículo estuvo muy cerca de colisionar con un peatón',
            'category' => 'Colisión',
            'severity' => 'critical',
        ],
        'ForwardCollisionWarning' => [
            'name' => 'Advertencia de colisión frontal',
            'description' => 'Sistema de advertencia activado por riesgo de colisión frontal',
            'category' => 'Colisión',
            'severity' => 'critical',
        ],
        'RearCollisionWarning' => [
            'name' => 'Advertencia de colisión trasera',
            'description' => 'Sistema de advertencia activado por riesgo de colisión trasera',
            'category' => 'Colisión',
            'severity' => 'critical',
        ],
        'VulnerableRoadUserCollisionWarning' => [
            'name' => 'Advertencia de colisión con usuario vulnerable',
            'description' => 'Advertencia de posible colisión con peatón, ciclista u otro usuario vulnerable de la vía',
            'category' => 'Colisión',
            'severity' => 'critical',
        ],
        'VehicleInBlindSpotWarning' => [
            'name' => 'Vehículo en punto ciego',
            'description' => 'Se detectó un vehículo en el punto ciego durante una maniobra',
            'category' => 'Colisión',
            'severity' => 'warning',
        ],

        // === DISTANCIA DE SEGUIMIENTO ===
        'FollowingDistance' => [
            'name' => 'Distancia de seguimiento insuficiente',
            'description' => 'El vehículo mantiene una distancia insegura con el vehículo de adelante',
            'category' => 'Distancia',
            'severity' => 'warning',
        ],
        'FollowingDistanceModerate' => [
            'name' => 'Distancia de seguimiento moderada',
            'description' => 'El vehículo mantiene una distancia moderadamente insegura con el vehículo de adelante',
            'category' => 'Distancia',
            'severity' => 'warning',
        ],
        'FollowingDistanceSevere' => [
            'name' => 'Distancia de seguimiento crítica',
            'description' => 'El vehículo mantiene una distancia peligrosamente corta con el vehículo de adelante',
            'category' => 'Distancia',
            'severity' => 'critical',
        ],
        'GenericTailgating' => [
            'name' => 'Conducción pegado al vehículo',
            'description' => 'El conductor está siguiendo demasiado cerca al vehículo de adelante',
            'category' => 'Distancia',
            'severity' => 'warning',
        ],

        // === DISTRACCIONES ===
        'GenericDistraction' => [
            'name' => 'Distracción detectada',
            'description' => 'Se detectó que el conductor está distraído',
            'category' => 'Distracción',
            'severity' => 'warning',
        ],
        'MobileUsage' => [
            'name' => 'Uso de celular',
            'description' => 'El conductor está usando el teléfono celular mientras conduce',
            'category' => 'Distracción',
            'severity' => 'critical',
        ],
        'Drowsy' => [
            'name' => 'Somnolencia detectada',
            'description' => 'Se detectaron signos de somnolencia o fatiga en el conductor',
            'category' => 'Distracción',
            'severity' => 'critical',
        ],
        'Eating' => [
            'name' => 'Comiendo mientras conduce',
            'description' => 'El conductor está comiendo mientras conduce',
            'category' => 'Distracción',
            'severity' => 'warning',
        ],
        'EatingDrinking' => [
            'name' => 'Comiendo o bebiendo',
            'description' => 'El conductor está comiendo o bebiendo mientras conduce',
            'category' => 'Distracción',
            'severity' => 'warning',
        ],
        'Drinking' => [
            'name' => 'Bebiendo mientras conduce',
            'description' => 'El conductor está bebiendo mientras conduce',
            'category' => 'Distracción',
            'severity' => 'warning',
        ],
        'Smoking' => [
            'name' => 'Fumando mientras conduce',
            'description' => 'El conductor está fumando mientras conduce',
            'category' => 'Distracción',
            'severity' => 'warning',
        ],
        'EdgeDistractedDriving' => [
            'name' => 'Conducción distraída (Edge)',
            'description' => 'Distracción detectada por el sistema Edge AI',
            'category' => 'Distracción',
            'severity' => 'warning',
        ],
        'LateResponse' => [
            'name' => 'Respuesta tardía',
            'description' => 'El conductor tardó en reaccionar ante una situación',
            'category' => 'Distracción',
            'severity' => 'warning',
        ],

        // === VIOLACIONES DE TRÁFICO ===
        'RanRedLight' => [
            'name' => 'Pasó semáforo en rojo',
            'description' => 'El vehículo cruzó una intersección con el semáforo en rojo',
            'category' => 'Violación',
            'severity' => 'critical',
        ],
        'RollingStop' => [
            'name' => 'Alto rodante',
            'description' => 'El vehículo no se detuvo completamente en una señal de alto',
            'category' => 'Violación',
            'severity' => 'warning',
        ],
        'DidNotYield' => [
            'name' => 'No cedió el paso',
            'description' => 'El conductor no cedió el paso cuando debía',
            'category' => 'Violación',
            'severity' => 'warning',
        ],
        'EdgeRailroadCrossingViolation' => [
            'name' => 'Violación en cruce ferroviario',
            'description' => 'El vehículo cometió una violación en un cruce de ferrocarril',
            'category' => 'Violación',
            'severity' => 'critical',
        ],
        'HosViolation' => [
            'name' => 'Violación de horas de servicio',
            'description' => 'El conductor excedió las horas de servicio permitidas (HOS)',
            'category' => 'Violación',
            'severity' => 'critical',
        ],
        'OtherViolation' => [
            'name' => 'Otra violación',
            'description' => 'Se detectó otra violación de tráfico no categorizada',
            'category' => 'Violación',
            'severity' => 'warning',
        ],
        'PolicyViolationMask' => [
            'name' => 'Violación de política (mascarilla)',
            'description' => 'El conductor no está usando mascarilla según la política de la empresa',
            'category' => 'Violación',
            'severity' => 'info',
        ],

        // === SEGURIDAD DEL CONDUCTOR ===
        'NoSeatbelt' => [
            'name' => 'Sin cinturón de seguridad',
            'description' => 'El conductor no está usando el cinturón de seguridad',
            'category' => 'Seguridad',
            'severity' => 'critical',
        ],
        'BluetoothHeadset' => [
            'name' => 'Usando auricular Bluetooth',
            'description' => 'El conductor está usando un auricular Bluetooth',
            'category' => 'Seguridad',
            'severity' => 'info',
        ],
        'ProtectiveEquipment' => [
            'name' => 'Equipo de protección',
            'description' => 'Evento relacionado con el uso de equipo de protección personal',
            'category' => 'Seguridad',
            'severity' => 'info',
        ],
        'Passenger' => [
            'name' => 'Pasajero detectado',
            'description' => 'Se detectó un pasajero en el vehículo',
            'category' => 'Seguridad',
            'severity' => 'info',
        ],

        // === MANIOBRAS ===
        'LaneDeparture' => [
            'name' => 'Salida de carril',
            'description' => 'El vehículo se salió de su carril sin señalizar',
            'category' => 'Maniobras',
            'severity' => 'warning',
        ],
        'UnsafeManeuver' => [
            'name' => 'Maniobra insegura',
            'description' => 'El conductor realizó una maniobra considerada insegura',
            'category' => 'Maniobras',
            'severity' => 'warning',
        ],
        'LeftTurn' => [
            'name' => 'Giro a la izquierda',
            'description' => 'El vehículo realizó un giro a la izquierda (posiblemente peligroso)',
            'category' => 'Maniobras',
            'severity' => 'info',
        ],
        'UTurn' => [
            'name' => 'Vuelta en U',
            'description' => 'El vehículo realizó una vuelta en U',
            'category' => 'Maniobras',
            'severity' => 'warning',
        ],
        'Reversing' => [
            'name' => 'Marcha atrás',
            'description' => 'El vehículo está en marcha atrás',
            'category' => 'Maniobras',
            'severity' => 'info',
        ],
        'UnsafeParking' => [
            'name' => 'Estacionamiento inseguro',
            'description' => 'El vehículo está estacionado en un lugar inseguro o no permitido',
            'category' => 'Maniobras',
            'severity' => 'warning',
        ],

        // === CONDUCCIÓN AGRESIVA/DEFENSIVA ===
        'AggressiveDriving' => [
            'name' => 'Conducción agresiva',
            'description' => 'Se detectó un patrón de conducción agresiva',
            'category' => 'Conducción',
            'severity' => 'critical',
        ],
        'DefensiveDriving' => [
            'name' => 'Conducción defensiva',
            'description' => 'El conductor demostró buenas prácticas de conducción defensiva',
            'category' => 'Conducción',
            'severity' => 'info',
        ],

        // === ESTABILIDAD DEL VEHÍCULO ===
        'RolloverProtection' => [
            'name' => 'Protección antivuelco activada',
            'description' => 'El sistema de protección antivuelco del vehículo se activó',
            'category' => 'Estabilidad',
            'severity' => 'critical',
        ],
        'YawControl' => [
            'name' => 'Control de derrape activado',
            'description' => 'El sistema de control de estabilidad (yaw) se activó',
            'category' => 'Estabilidad',
            'severity' => 'warning',
        ],
        'HighSpeedSuddenDisconnect' => [
            'name' => 'Desconexión súbita a alta velocidad',
            'description' => 'El dispositivo se desconectó súbitamente mientras el vehículo iba a alta velocidad',
            'category' => 'Estabilidad',
            'severity' => 'critical',
        ],

        // === CONTEXTO DE CONDICIONES ===
        'ContextConstructionOrWorkZone' => [
            'name' => 'Zona de construcción',
            'description' => 'El evento ocurrió en una zona de construcción u obras',
            'category' => 'Contexto',
            'severity' => 'info',
        ],
        'ContextSnowyOrIcy' => [
            'name' => 'Condiciones de nieve/hielo',
            'description' => 'El evento ocurrió en condiciones de nieve o hielo',
            'category' => 'Contexto',
            'severity' => 'info',
        ],
        'ContextVulnerableRoadUser' => [
            'name' => 'Usuario vulnerable en la vía',
            'description' => 'Había un usuario vulnerable (peatón, ciclista) cerca durante el evento',
            'category' => 'Contexto',
            'severity' => 'info',
        ],
        'ContextWet' => [
            'name' => 'Condiciones de lluvia/mojado',
            'description' => 'El evento ocurrió en condiciones de lluvia o pavimento mojado',
            'category' => 'Contexto',
            'severity' => 'info',
        ],

        // === OTROS ===
        'Idling' => [
            'name' => 'Vehículo en ralentí',
            'description' => 'El vehículo estuvo en ralentí por un período prolongado',
            'category' => 'Operación',
            'severity' => 'info',
        ],
        'ObstructedCamera' => [
            'name' => 'Cámara obstruida',
            'description' => 'La cámara del vehículo está obstruida o tapada',
            'category' => 'Equipo',
            'severity' => 'warning',
        ],
        'Invalid' => [
            'name' => 'Evento inválido',
            'description' => 'El evento fue marcado como inválido',
            'category' => 'Sistema',
            'severity' => 'info',
        ],
    ];

    /**
     * Categorías de eventos con sus traducciones.
     */
    public const CATEGORIES = [
        'Conducción' => 'Driving',
        'Velocidad' => 'Speed',
        'Colisión' => 'Collision',
        'Distancia' => 'Distance',
        'Distracción' => 'Distraction',
        'Violación' => 'Violation',
        'Seguridad' => 'Safety',
        'Maniobras' => 'Maneuvers',
        'Estabilidad' => 'Stability',
        'Contexto' => 'Context',
        'Operación' => 'Operation',
        'Equipo' => 'Equipment',
        'Sistema' => 'System',
    ];

    /**
     * Estados de eventos con sus traducciones.
     */
    public const EVENT_STATES = [
        'unknown' => [
            'name' => 'Desconocido',
            'description' => 'Estado del evento desconocido',
        ],
        'needsReview' => [
            'name' => 'Necesita revisión',
            'description' => 'El evento necesita ser revisado por un supervisor',
        ],
        'reviewed' => [
            'name' => 'Revisado',
            'description' => 'El evento ha sido revisado',
        ],
        'needsCoaching' => [
            'name' => 'Necesita coaching',
            'description' => 'El conductor necesita recibir coaching sobre este evento',
        ],
        'coached' => [
            'name' => 'Coaching completado',
            'description' => 'El conductor ha recibido coaching sobre este evento',
        ],
        'dismissed' => [
            'name' => 'Descartado',
            'description' => 'El evento ha sido descartado',
        ],
        'needsRecognition' => [
            'name' => 'Necesita reconocimiento',
            'description' => 'El conductor merece reconocimiento por su conducta',
        ],
        'recognized' => [
            'name' => 'Reconocido',
            'description' => 'El conductor ha sido reconocido por su buena conducta',
        ],
    ];

    /**
     * Traducir un behavior label al español.
     */
    public static function translate(string $label): array
    {
        return self::TRANSLATIONS[$label] ?? [
            'name' => $label,
            'description' => "Evento de seguridad: {$label}",
            'category' => 'Otro',
            'severity' => 'info',
        ];
    }

    /**
     * Obtener solo el nombre traducido.
     */
    public static function getName(string $label): string
    {
        return self::TRANSLATIONS[$label]['name'] ?? $label;
    }

    /**
     * Obtener solo la descripción traducida.
     */
    public static function getDescription(string $label): string
    {
        return self::TRANSLATIONS[$label]['description'] ?? "Evento de seguridad: {$label}";
    }

    /**
     * Obtener la severidad de un label.
     */
    public static function getSeverity(string $label): string
    {
        return self::TRANSLATIONS[$label]['severity'] ?? 'info';
    }

    /**
     * Obtener la categoría de un label.
     */
    public static function getCategory(string $label): string
    {
        return self::TRANSLATIONS[$label]['category'] ?? 'Otro';
    }

    /**
     * Traducir un array de behavior labels.
     */
    public static function translateMany(array $labels): array
    {
        $translated = [];
        
        foreach ($labels as $labelData) {
            $label = is_array($labelData) 
                ? ($labelData['label'] ?? $labelData['name'] ?? null)
                : $labelData;
            
            if ($label) {
                $translation = self::translate($label);
                $translated[] = [
                    'original' => $label,
                    'source' => is_array($labelData) ? ($labelData['source'] ?? 'automated') : 'automated',
                    ...$translation,
                ];
            }
        }
        
        return $translated;
    }

    /**
     * Obtener el label principal traducido de un array de labels.
     */
    public static function getPrimaryTranslated(array $labels): ?string
    {
        if (empty($labels)) {
            return null;
        }

        $firstLabel = $labels[0];
        $label = is_array($firstLabel) 
            ? ($firstLabel['label'] ?? $firstLabel['name'] ?? null)
            : $firstLabel;

        return $label ? self::getName($label) : null;
    }

    /**
     * Traducir un estado de evento.
     */
    public static function translateState(string $state): array
    {
        return self::EVENT_STATES[$state] ?? [
            'name' => $state,
            'description' => "Estado: {$state}",
        ];
    }

    /**
     * Obtener el nombre traducido de un estado.
     */
    public static function getStateName(string $state): string
    {
        return self::EVENT_STATES[$state]['name'] ?? $state;
    }

    /**
     * Obtener todos los labels disponibles agrupados por categoría.
     */
    public static function getAllByCategory(): array
    {
        $grouped = [];
        
        foreach (self::TRANSLATIONS as $label => $data) {
            $category = $data['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][$label] = $data;
        }
        
        return $grouped;
    }

    /**
     * Obtener todos los labels de una severidad específica.
     */
    public static function getBySeverity(string $severity): array
    {
        return array_filter(
            self::TRANSLATIONS,
            fn($data) => $data['severity'] === $severity
        );
    }

    /**
     * Verificar si un label es crítico.
     */
    public static function isCritical(string $label): bool
    {
        return self::getSeverity($label) === 'critical';
    }

    /**
     * Verificar si un label es de advertencia.
     */
    public static function isWarning(string $label): bool
    {
        return self::getSeverity($label) === 'warning';
    }
}
