/**
 * Types for Incidents and Safety Signals.
 */

import type { Severity } from './severity';

// Re-export for consumers that used SignalSeverity
export type { Severity as SignalSeverity } from './severity';

// ============================================================================
// SAFETY SIGNALS
// ============================================================================

export type EventState = 'needsReview' | 'needsCoaching' | 'dismissed' | 'coached';

export interface SafetySignalListItem {
    id: number;
    samsara_event_id: string;
    vehicle_id: string | null;
    vehicle_name: string | null;
    driver_id: string | null;
    driver_name: string | null;
    primary_behavior_label: string | null;
    primary_label_translated: string | null;
    behavior_labels: string[] | null;
    severity: Severity;
    severity_label: string;
    event_state: EventState | null;
    event_state_translated: string | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    max_acceleration_g: number | null;
    media_urls: MediaUrl[] | null;
    inbox_event_url: string | null;
    occurred_at: string | null;
    occurred_at_human: string | null;
    created_at: string | null;
    used_in_evidence: boolean;
}

export interface SafetySignalDetail extends SafetySignalListItem {
    primary_label_data: BehaviorLabelData | null;
    behavior_labels_translated: BehaviorLabelData[];
    context_labels: string[] | null;
    speeding_metadata: SpeedingMetadata | null;
    incident_report_url: string | null;
    samsara_created_at: string | null;
    incidents: LinkedIncident[];
}

export interface MediaUrl {
    url?: string;
    mediaUrl?: string;
    type?: string;
    input?: string;
}

export interface BehaviorLabelData {
    key: string;
    name: string;
    description: string;
    category: string;
    severity: Severity;
}

export interface SpeedingMetadata {
    speedMph?: number;
    limitMph?: number;
    overLimitMph?: number;
}

export interface LinkedIncident {
    id: number;
    incident_type: string;
    type_label: string;
    priority: string;
    status: string;
    pivot_role: 'supporting' | 'contradicting' | 'context';
}

export interface SafetySignalStats {
    total: number;
    critical: number;
    needs_review: number;
    today: number;
}

// ============================================================================
// INCIDENTS
// ============================================================================

export type IncidentType = 'collision' | 'emergency' | 'pattern' | 'safety_violation' | 'tampering' | 'unknown';
export type IncidentPriority = 'P1' | 'P2' | 'P3' | 'P4';
export type IncidentStatus = 'open' | 'investigating' | 'pending_action' | 'resolved' | 'false_positive';
export type IncidentSource = 'webhook' | 'auto_pattern' | 'auto_aggregator' | 'manual';
export type SubjectType = 'driver' | 'vehicle';

export interface IncidentListItem {
    id: number;
    incident_type: IncidentType;
    type_label: string;
    priority: IncidentPriority;
    priority_label: string;
    severity: Severity;
    severity_label: string;
    status: IncidentStatus;
    status_label: string;
    subject_type: SubjectType | null;
    subject_id: string | null;
    subject_name: string | null;
    source: IncidentSource;
    ai_summary: string | null;
    detected_at: string | null;
    detected_at_human: string | null;
    resolved_at: string | null;
    safety_signals_count: number;
    is_high_priority: boolean;
    is_resolved: boolean;
}

export interface IncidentDetail extends IncidentListItem {
    samsara_event_id: string | null;
    dedupe_key: string | null;
    ai_assessment: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    resolved_at_human: string | null;
    created_at: string | null;
    safety_signals: LinkedSafetySignal[];
}

export interface LinkedSafetySignal {
    id: number;
    samsara_event_id: string;
    vehicle_name: string | null;
    driver_name: string | null;
    primary_behavior_label: string | null;
    primary_label_translated: string | null;
    severity: Severity;
    severity_label: string;
    address: string | null;
    occurred_at: string | null;
    occurred_at_human: string | null;
    pivot_role: 'supporting' | 'contradicting' | 'context';
    pivot_relevance_score: number;
}

export interface IncidentStats {
    total: number;
    open: number;
    high_priority: number;
    resolved_today: number;
}

export interface PriorityCounts {
    P1: number;
    P2: number;
    P3: number;
    P4: number;
}

// ============================================================================
// UI OPTIONS
// ============================================================================

export const INCIDENT_STATUS_OPTIONS: { value: IncidentStatus; label: string; color: string }[] = [
    { value: 'open', label: 'Abierto', color: 'amber' },
    { value: 'investigating', label: 'En investigación', color: 'blue' },
    { value: 'pending_action', label: 'Pendiente', color: 'orange' },
    { value: 'resolved', label: 'Resuelto', color: 'emerald' },
    { value: 'false_positive', label: 'Falso positivo', color: 'slate' },
];

export const INCIDENT_PRIORITY_OPTIONS: { value: IncidentPriority; label: string; color: string }[] = [
    { value: 'P1', label: 'P1 - Crítico', color: 'red' },
    { value: 'P2', label: 'P2 - Alto', color: 'orange' },
    { value: 'P3', label: 'P3 - Medio', color: 'yellow' },
    { value: 'P4', label: 'P4 - Bajo', color: 'slate' },
];

export const INCIDENT_TYPE_OPTIONS: { value: IncidentType; label: string; icon: string }[] = [
    { value: 'collision', label: 'Colisión', icon: 'car' },
    { value: 'emergency', label: 'Emergencia', icon: 'alert-triangle' },
    { value: 'pattern', label: 'Patrón', icon: 'activity' },
    { value: 'safety_violation', label: 'Violación', icon: 'shield-alert' },
    { value: 'tampering', label: 'Manipulación', icon: 'wrench' },
    { value: 'unknown', label: 'Desconocido', icon: 'help-circle' },
];

export const SEVERITY_OPTIONS: { value: Severity; label: string; color: string }[] = [
    { value: 'critical', label: 'Crítico', color: 'red' },
    { value: 'warning', label: 'Advertencia', color: 'amber' },
    { value: 'info', label: 'Información', color: 'blue' },
];
