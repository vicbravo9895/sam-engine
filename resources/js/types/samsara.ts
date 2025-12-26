/**
 * Tipos para eventos de Samsara y revisión humana.
 */

export type HumanStatus = 'pending' | 'reviewed' | 'flagged' | 'resolved' | 'false_positive';
export type AiStatus = 'pending' | 'processing' | 'investigating' | 'completed' | 'failed';
export type Severity = 'info' | 'warning' | 'critical';
export type UrgencyLevel = 'high' | 'medium' | 'low';

export interface EventUser {
    id: number;
    name: string;
}

export interface EventComment {
    id: number;
    content: string;
    user: EventUser | null;
    created_at: string;
    created_at_human: string;
}

export interface EventActivity {
    id: number;
    action: string;
    action_label: string;
    action_icon: string;
    is_ai_action: boolean;
    user: EventUser | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
    created_at_human: string;
}

export interface HumanReviewData {
    human_status: HumanStatus;
    human_status_label: string;
    reviewed_by?: EventUser | null;
    reviewed_at?: string | null;
    reviewed_at_human?: string | null;
    needs_attention: boolean;
    urgency_level: UrgencyLevel;
    comments_count?: number;
}

export interface EventListItem extends HumanReviewData {
    id: number;
    samsara_event_id?: string | null;
    event_type?: string | null;
    event_title?: string | null;
    event_description?: string | null;
    severity: Severity;
    severity_label?: string | null;
    ai_status: AiStatus;
    ai_status_label?: string | null;
    vehicle_name?: string | null;
    driver_name?: string | null;
    occurred_at?: string | null;
    occurred_at_human?: string | null;
    created_at?: string | null;
    ai_message_preview?: string | null;
    ai_assessment_view?: {
        verdict?: string | null;
        likelihood?: string | null;
        reasoning?: string | null;
    } | null;
    event_icon?: string | null;
    verdict_summary?: {
        verdict: string;
        likelihood?: string | null;
        urgency: UrgencyLevel;
    } | null;
    investigation_summary?: {
        label: string;
        items: string[];
    }[];
    has_images?: boolean;
    investigation_metadata?: {
        count: number;
        max_investigations: number;
    } | null;
}

export interface ReviewApiResponse<T = unknown> {
    success: boolean;
    message?: string;
    data: T;
}

// Human status options for UI
export const HUMAN_STATUS_OPTIONS: { value: HumanStatus; label: string; color: string; icon: string }[] = [
    { value: 'pending', label: 'Sin revisar', color: 'slate', icon: 'clock' },
    { value: 'reviewed', label: 'Revisado', color: 'blue', icon: 'eye' },
    { value: 'flagged', label: 'Marcado', color: 'amber', icon: 'flag' },
    { value: 'resolved', label: 'Resuelto', color: 'emerald', icon: 'check-circle' },
    { value: 'false_positive', label: 'Falso positivo', color: 'slate', icon: 'slash' },
];

