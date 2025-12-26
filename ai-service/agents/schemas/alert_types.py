"""
Definición de tipos de alerta y categorías.
Enum central que define todos los tipos de alertas que el sistema puede manejar.
"""

from enum import Enum


class AlertCategory(str, Enum):
    """Categorías principales de alertas."""
    PANIC = "panic"
    SAFETY_EVENT = "safety_event"
    TAMPERING = "tampering"
    CONNECTIVITY = "connectivity"
    UNKNOWN = "unknown"


class AlertType(str, Enum):
    """
    Tipos específicos de alertas de Samsara.
    
    Cada tipo tiene:
    - Una categoría padre
    - Una prioridad default
    - Una estrategia de investigación asociada
    """
    
    # =========================================================================
    # PANIC ALERTS - Botones de pánico y emergencias
    # =========================================================================
    PANIC_BUTTON = "panic_button"
    
    # =========================================================================
    # SAFETY EVENTS - Comportamiento del conductor
    # =========================================================================
    SAFETY_DRINKING = "safety_drinking"           # Conductor bebiendo
    SAFETY_DISTRACTED = "safety_distracted"       # Conductor distraído
    SAFETY_DROWSY = "safety_drowsy"               # Conductor somnoliento
    SAFETY_CELL_PHONE = "safety_cell_phone"       # Uso de celular
    SAFETY_SMOKING = "safety_smoking"             # Conductor fumando
    
    # =========================================================================
    # SAFETY EVENTS - Cámara y visibilidad
    # =========================================================================
    SAFETY_CAMERA_OBSTRUCTION = "safety_camera_obstruction"  # Cámara obstruida
    SAFETY_CAMERA_COVERED = "safety_camera_covered"          # Cámara tapada
    
    # =========================================================================
    # SAFETY EVENTS - Pasajeros
    # =========================================================================
    SAFETY_UNAUTHORIZED_PASSENGER = "safety_unauthorized_passenger"  # Pasajero no autorizado
    SAFETY_PASSENGER_DETECTED = "safety_passenger_detected"          # Pasajero detectado
    
    # =========================================================================
    # SAFETY EVENTS - Conducción
    # =========================================================================
    SAFETY_HARSH_BRAKING = "safety_harsh_braking"           # Frenado brusco
    SAFETY_HARSH_ACCELERATION = "safety_harsh_acceleration"  # Aceleración brusca
    SAFETY_HARSH_TURN = "safety_harsh_turn"                 # Giro brusco
    SAFETY_SPEEDING = "safety_speeding"                     # Exceso de velocidad
    SAFETY_COLLISION = "safety_collision"                   # Colisión detectada
    SAFETY_NEAR_COLLISION = "safety_near_collision"         # Casi colisión
    SAFETY_LANE_DEPARTURE = "safety_lane_departure"         # Salida de carril
    SAFETY_FOLLOWING_DISTANCE = "safety_following_distance" # Distancia de seguimiento
    
    # =========================================================================
    # TAMPERING - Manipulación del dispositivo
    # =========================================================================
    TAMPERING_DEVICE_UNPLUGGED = "tampering_device_unplugged"  # Dispositivo desconectado
    TAMPERING_JAMMING = "tampering_jamming"                    # Interferencia detectada
    TAMPERING_GPS_SPOOFING = "tampering_gps_spoofing"          # Falsificación GPS
    
    # =========================================================================
    # CONNECTIVITY - Problemas de conexión
    # =========================================================================
    CONNECTION_LOST = "connection_lost"              # Pérdida de conexión
    CONNECTION_POOR_SIGNAL = "connection_poor_signal" # Señal débil
    
    # =========================================================================
    # UNKNOWN - Tipo no reconocido
    # =========================================================================
    UNKNOWN = "unknown"
    
    @classmethod
    def get_category(cls, alert_type: "AlertType") -> AlertCategory:
        """Obtiene la categoría para un tipo de alerta."""
        category_map = {
            # Panic
            cls.PANIC_BUTTON: AlertCategory.PANIC,
            
            # Safety Events - Comportamiento
            cls.SAFETY_DRINKING: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_DISTRACTED: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_DROWSY: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_CELL_PHONE: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_SMOKING: AlertCategory.SAFETY_EVENT,
            
            # Safety Events - Cámara
            cls.SAFETY_CAMERA_OBSTRUCTION: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_CAMERA_COVERED: AlertCategory.SAFETY_EVENT,
            
            # Safety Events - Pasajeros
            cls.SAFETY_UNAUTHORIZED_PASSENGER: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_PASSENGER_DETECTED: AlertCategory.SAFETY_EVENT,
            
            # Safety Events - Conducción
            cls.SAFETY_HARSH_BRAKING: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_HARSH_ACCELERATION: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_HARSH_TURN: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_SPEEDING: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_COLLISION: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_NEAR_COLLISION: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_LANE_DEPARTURE: AlertCategory.SAFETY_EVENT,
            cls.SAFETY_FOLLOWING_DISTANCE: AlertCategory.SAFETY_EVENT,
            
            # Tampering
            cls.TAMPERING_DEVICE_UNPLUGGED: AlertCategory.TAMPERING,
            cls.TAMPERING_JAMMING: AlertCategory.TAMPERING,
            cls.TAMPERING_GPS_SPOOFING: AlertCategory.TAMPERING,
            
            # Connectivity
            cls.CONNECTION_LOST: AlertCategory.CONNECTIVITY,
            cls.CONNECTION_POOR_SIGNAL: AlertCategory.CONNECTIVITY,
        }
        return category_map.get(alert_type, AlertCategory.UNKNOWN)
    
    @classmethod
    def get_default_priority(cls, alert_type: "AlertType") -> str:
        """Obtiene la prioridad default para un tipo de alerta."""
        critical_types = {
            cls.PANIC_BUTTON,
            cls.SAFETY_COLLISION,
            cls.TAMPERING_DEVICE_UNPLUGGED,
            cls.TAMPERING_JAMMING,
        }
        high_types = {
            cls.SAFETY_DRINKING,
            cls.SAFETY_DROWSY,
            cls.SAFETY_NEAR_COLLISION,
            cls.SAFETY_CAMERA_OBSTRUCTION,
            cls.SAFETY_CAMERA_COVERED,
            cls.CONNECTION_LOST,
        }
        medium_types = {
            cls.SAFETY_DISTRACTED,
            cls.SAFETY_CELL_PHONE,
            cls.SAFETY_HARSH_BRAKING,
            cls.SAFETY_SPEEDING,
            cls.SAFETY_UNAUTHORIZED_PASSENGER,
        }
        
        if alert_type in critical_types:
            return "critical"
        elif alert_type in high_types:
            return "high"
        elif alert_type in medium_types:
            return "medium"
        return "low"


# Mapeo de nombres de Samsara a AlertType
SAMSARA_EVENT_TYPE_MAP = {
    # Panic
    "panicButton": AlertType.PANIC_BUTTON,
    "panic_button": AlertType.PANIC_BUTTON,
    "Panic Button": AlertType.PANIC_BUTTON,
    
    # Comportamiento del conductor
    "drinkingDetected": AlertType.SAFETY_DRINKING,
    "drinking": AlertType.SAFETY_DRINKING,
    "distractedDriving": AlertType.SAFETY_DISTRACTED,
    "distracted": AlertType.SAFETY_DISTRACTED,
    "drowsyDriving": AlertType.SAFETY_DROWSY,
    "drowsy": AlertType.SAFETY_DROWSY,
    "cellPhoneUsage": AlertType.SAFETY_CELL_PHONE,
    "cellPhone": AlertType.SAFETY_CELL_PHONE,
    "smokingDetected": AlertType.SAFETY_SMOKING,
    "smoking": AlertType.SAFETY_SMOKING,
    
    # Cámara
    "cameraObstruction": AlertType.SAFETY_CAMERA_OBSTRUCTION,
    "obstructedCamera": AlertType.SAFETY_CAMERA_OBSTRUCTION,
    "cameraCovered": AlertType.SAFETY_CAMERA_COVERED,
    
    # Pasajeros
    "unauthorizedPassenger": AlertType.SAFETY_UNAUTHORIZED_PASSENGER,
    "passengerDetected": AlertType.SAFETY_PASSENGER_DETECTED,
    
    # Conducción
    "harshBrake": AlertType.SAFETY_HARSH_BRAKING,
    "harshBraking": AlertType.SAFETY_HARSH_BRAKING,
    "harshAcceleration": AlertType.SAFETY_HARSH_ACCELERATION,
    "harshAccel": AlertType.SAFETY_HARSH_ACCELERATION,
    "harshTurn": AlertType.SAFETY_HARSH_TURN,
    "speeding": AlertType.SAFETY_SPEEDING,
    "collision": AlertType.SAFETY_COLLISION,
    "crash": AlertType.SAFETY_COLLISION,
    "nearCollision": AlertType.SAFETY_NEAR_COLLISION,
    "forwardCollisionWarning": AlertType.SAFETY_NEAR_COLLISION,
    "laneDeparture": AlertType.SAFETY_LANE_DEPARTURE,
    "laneDepartureWarning": AlertType.SAFETY_LANE_DEPARTURE,
    "followingDistance": AlertType.SAFETY_FOLLOWING_DISTANCE,
    "tailgating": AlertType.SAFETY_FOLLOWING_DISTANCE,
    
    # Tampering
    "deviceUnplugged": AlertType.TAMPERING_DEVICE_UNPLUGGED,
    "unplugged": AlertType.TAMPERING_DEVICE_UNPLUGGED,
    "jamming": AlertType.TAMPERING_JAMMING,
    "jammingDetected": AlertType.TAMPERING_JAMMING,
    "gpsSpoofing": AlertType.TAMPERING_GPS_SPOOFING,
    
    # Connectivity
    "connectionLost": AlertType.CONNECTION_LOST,
    "offline": AlertType.CONNECTION_LOST,
    "poorSignal": AlertType.CONNECTION_POOR_SIGNAL,
}


def detect_alert_type(event_type: str, behavior_label: str = None) -> AlertType:
    """
    Detecta el tipo de alerta basándose en el event_type y behavior_label de Samsara.
    
    Args:
        event_type: Tipo de evento del webhook (e.g., "AlertIncident", "SafetyEvent")
        behavior_label: Label del comportamiento específico (e.g., "drinking", "harshBrake")
        
    Returns:
        AlertType correspondiente
    """
    # Primero intentar con el behavior_label si existe
    if behavior_label:
        normalized = behavior_label.strip()
        if normalized in SAMSARA_EVENT_TYPE_MAP:
            return SAMSARA_EVENT_TYPE_MAP[normalized]
    
    # Luego intentar con el event_type
    normalized = event_type.strip()
    if normalized in SAMSARA_EVENT_TYPE_MAP:
        return SAMSARA_EVENT_TYPE_MAP[normalized]
    
    # Buscar coincidencias parciales (case-insensitive)
    event_lower = normalized.lower()
    for key, value in SAMSARA_EVENT_TYPE_MAP.items():
        if key.lower() in event_lower or event_lower in key.lower():
            return value
    
    return AlertType.UNKNOWN


