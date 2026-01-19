import { format, formatDistanceToNow } from 'date-fns';
import { es } from 'date-fns/locale';
import { toZonedTime } from 'date-fns-tz';

/**
 * Format a date to the company's timezone
 */
export function formatInTimezone(
    date: Date | string,
    timezone: string,
    formatStr: string = 'PPp'
): string {
    const dateObj = typeof date === 'string' ? new Date(date) : date;
    const zonedDate = toZonedTime(dateObj, timezone);
    return format(zonedDate, formatStr, { locale: es });
}

/**
 * Format a date as relative time (e.g., "hace 5 minutos")
 */
export function formatRelative(date: Date | string): string {
    const dateObj = typeof date === 'string' ? new Date(date) : date;
    return formatDistanceToNow(dateObj, { addSuffix: true, locale: es });
}

/**
 * Format a date for display with timezone conversion
 * Common formats:
 * - 'PPp' = "19 de enero de 2026, 10:30"
 * - 'Pp' = "19/01/2026, 10:30"
 * - 'HH:mm' = "10:30"
 * - 'dd/MM HH:mm' = "19/01 10:30"
 * - 'PPPp' = "19 de enero de 2026 a las 10:30"
 */
export function formatDate(
    date: Date | string | null | undefined,
    timezone: string,
    formatStr: string = 'Pp'
): string {
    if (!date) return '-';
    
    try {
        let dateObj: Date;
        
        if (typeof date === 'string') {
            // The backend sends dates with incorrect offset (e.g., "04:55-06:00" when it should be UTC)
            // We need to treat the time portion as UTC by removing the offset
            // Extract the datetime part without the timezone offset and treat as UTC
            const dateWithoutOffset = date.replace(/[+-]\d{2}:\d{2}$/, '');
            dateObj = new Date(dateWithoutOffset + 'Z'); // Treat as UTC
        } else {
            dateObj = date;
        }
        
        // Check if date is valid
        if (isNaN(dateObj.getTime())) {
            return '-';
        }
        
        // Convert from UTC to the company's timezone
        const zonedDate = toZonedTime(dateObj, timezone);
        return format(zonedDate, formatStr, { locale: es });
    } catch {
        return '-';
    }
}

/**
 * Format time only in timezone
 */
export function formatTime(
    date: Date | string | null | undefined,
    timezone: string
): string {
    return formatDate(date, timezone, 'HH:mm:ss');
}

/**
 * Format date and time compactly
 */
export function formatDateTime(
    date: Date | string | null | undefined,
    timezone: string
): string {
    return formatDate(date, timezone, 'dd/MM/yyyy HH:mm');
}

/**
 * Format date only (no time)
 */
export function formatDateOnly(
    date: Date | string | null | undefined,
    timezone: string
): string {
    return formatDate(date, timezone, 'dd/MM/yyyy');
}
