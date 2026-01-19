import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import { formatDate, formatDateTime, formatDateOnly, formatTime, formatRelative } from '@/lib/date-utils';

/**
 * Hook to access the company timezone and format dates accordingly
 */
export function useTimezone() {
    const props = usePage<SharedData>().props;
    const timezone = props.timezone ?? 'America/Mexico_City';

    const formatInTz = useCallback(
        (date: Date | string | null | undefined, formatStr?: string) => {
            return formatDate(date, timezone, formatStr);
        },
        [timezone]
    );

    const formatDateTimeTz = useCallback(
        (date: Date | string | null | undefined) => {
            return formatDateTime(date, timezone);
        },
        [timezone]
    );

    const formatDateOnlyTz = useCallback(
        (date: Date | string | null | undefined) => {
            return formatDateOnly(date, timezone);
        },
        [timezone]
    );

    const formatTimeTz = useCallback(
        (date: Date | string | null | undefined) => {
            return formatTime(date, timezone);
        },
        [timezone]
    );

    return {
        timezone,
        formatDate: formatInTz,
        formatDateTime: formatDateTimeTz,
        formatDateOnly: formatDateOnlyTz,
        formatTime: formatTimeTz,
        formatRelative,
    };
}
