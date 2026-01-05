import { cn } from '@/lib/utils';
import { useMemo } from 'react';
import Markdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { DashcamMediaCard } from './rich-cards/dashcam-media-card';
import { FleetReportCard } from './rich-cards/fleet-report-card';
import { LocationCard } from './rich-cards/location-card';
import { SafetyEventsCard } from './rich-cards/safety-events-card';
import { TripsCard } from './rich-cards/trips-card';
import { VehicleStatsCard } from './rich-cards/vehicle-stats-card';

interface MarkdownContentProps {
    content: string;
    className?: string;
    isStreaming?: boolean;
}

// Regex to detect special blocks: :::type {json} ::: or :::type\n{json}\n:::
// Flexible format: allows spaces, newlines, and common typos
// Updated to be more robust: matches :::type followed by whitespace/newlines, then JSON object, then closing :::
// Uses a balanced brace matcher approach by finding the first { and matching until the closing } at the same level
const RICH_BLOCK_REGEX = /:::(location|vehicleStats|dashcamMedia|dashamMedia|safetyEvents|trips|fleetReport)[\s\n]*(\{[\s\S]*?\})[\s\n]*:::/g;

// Map typos to correct types
const TYPE_CORRECTIONS: Record<string, string> = {
    dashamMedia: 'dashcamMedia',
};

type RichBlockType = 'location' | 'vehicleStats' | 'dashcamMedia' | 'safetyEvents' | 'trips' | 'fleetReport';

interface RichBlock {
    type: RichBlockType;
    data: any;
}

interface ContentPart {
    type: 'markdown' | 'richBlock';
    content?: string;
    block?: RichBlock;
}

function parseContent(content: string): ContentPart[] {
    const parts: ContentPart[] = [];
    let lastIndex = 0;
    let match;

    // Reset regex
    RICH_BLOCK_REGEX.lastIndex = 0;

    while ((match = RICH_BLOCK_REGEX.exec(content)) !== null) {
        // Add markdown before this block
        if (match.index > lastIndex) {
            parts.push({
                type: 'markdown',
                content: content.slice(lastIndex, match.index),
            });
        }

        // Try to find the actual JSON object by looking for balanced braces
        // This handles cases where there might be text after :::type before the JSON
        // Search from the end of the type name
        const typeEnd = match.index + match[0].indexOf(match[1]) + match[1].length;
        const blockStart = content.indexOf('{', typeEnd);
        if (blockStart === -1 || blockStart > match.index + match[0].length) {
            // No JSON found in expected range, try using the regex capture
            try {
                const jsonData = JSON.parse(match[2]);
                const rawType = match[1];
                const correctedType = (TYPE_CORRECTIONS[rawType] || rawType) as RichBlockType;
                
                parts.push({
                    type: 'richBlock',
                    block: {
                        type: correctedType,
                        data: jsonData,
                    },
                });
                lastIndex = match.index + match[0].length;
                continue;
            } catch {
                // If JSON parsing fails, treat as markdown
                parts.push({
                    type: 'markdown',
                    content: match[0],
                });
                lastIndex = match.index + match[0].length;
                continue;
            }
        }

        // Find the matching closing brace
        let braceCount = 0;
        let jsonEnd = blockStart;
        let inString = false;
        let escapeNext = false;

        for (let i = blockStart; i < content.length; i++) {
            const char = content[i];
            
            if (escapeNext) {
                escapeNext = false;
                continue;
            }
            
            if (char === '\\') {
                escapeNext = true;
                continue;
            }
            
            if (char === '"' && !escapeNext) {
                inString = !inString;
                continue;
            }
            
            if (!inString) {
                if (char === '{') {
                    braceCount++;
                } else if (char === '}') {
                    braceCount--;
                    if (braceCount === 0) {
                        jsonEnd = i + 1;
                        break;
                    }
                }
            }
        }

        // Extract JSON string
        const jsonString = content.slice(blockStart, jsonEnd);
        
        // Find the closing ::: after the JSON
        const closingMarker = content.indexOf(':::', jsonEnd);
        if (closingMarker === -1) {
            // No closing marker, treat as markdown
            parts.push({
                type: 'markdown',
                content: match[0],
            });
            lastIndex = match.index + match[0].length;
            continue;
        }

        // Parse the JSON block
        try {
            const jsonData = JSON.parse(jsonString);
            // Apply type corrections for common typos
            const rawType = match[1];
            const correctedType = (TYPE_CORRECTIONS[rawType] || rawType) as RichBlockType;
            
            parts.push({
                type: 'richBlock',
                block: {
                    type: correctedType,
                    data: jsonData,
                },
            });
            
            lastIndex = closingMarker + 3; // Move past the closing :::
        } catch {
            // If JSON parsing fails, treat as markdown
            parts.push({
                type: 'markdown',
                content: match[0],
            });
            lastIndex = match.index + match[0].length;
        }
    }

    // Add remaining markdown
    if (lastIndex < content.length) {
        parts.push({
            type: 'markdown',
            content: content.slice(lastIndex),
        });
    }

    return parts;
}

function RichBlockRenderer({ block }: { block: RichBlock }) {
    switch (block.type) {
        case 'location':
            return <LocationCard data={block.data} />;
        case 'vehicleStats':
            return <VehicleStatsCard data={block.data} />;
        case 'dashcamMedia':
            return <DashcamMediaCard data={block.data} />;
        case 'safetyEvents':
            return <SafetyEventsCard data={block.data} />;
        case 'trips':
            return <TripsCard data={block.data} />;
        case 'fleetReport':
            return <FleetReportCard data={block.data} />;
        default:
            return null;
    }
}

function MarkdownRenderer({ content }: { content: string }) {
    return (
        <Markdown
            remarkPlugins={[remarkGfm]}
            components={{
                // Encabezados
                h1: ({ children }) => (
                    <h1 className="mb-3 mt-4 text-lg font-bold first:mt-0">{children}</h1>
                ),
                h2: ({ children }) => (
                    <h2 className="mb-2 mt-3 text-base font-semibold first:mt-0">{children}</h2>
                ),
                h3: ({ children }) => (
                    <h3 className="mb-2 mt-2 text-sm font-semibold first:mt-0">{children}</h3>
                ),

                // Párrafos
                p: ({ children }) => (
                    <p className="mb-2 leading-relaxed last:mb-0">{children}</p>
                ),

                // Listas
                ul: ({ children }) => (
                    <ul className="mb-2 ml-4 list-disc space-y-1 last:mb-0">{children}</ul>
                ),
                ol: ({ children }) => (
                    <ol className="mb-2 ml-4 list-decimal space-y-1 last:mb-0">{children}</ol>
                ),
                li: ({ children }) => <li className="leading-relaxed">{children}</li>,

                // Código inline
                code: ({ children, className }) => {
                    const isBlock = className?.includes('language-');
                    if (isBlock) {
                        return <code className={cn('block', className)}>{children}</code>;
                    }
                    return (
                        <code className="bg-muted rounded px-1.5 py-0.5 font-mono text-xs">
                            {children}
                        </code>
                    );
                },

                // Bloques de código
                pre: ({ children }) => (
                    <pre className="bg-muted/80 my-2 overflow-x-auto rounded-lg p-3 font-mono text-xs">
                        {children}
                    </pre>
                ),

                // Links
                a: ({ href, children }) => (
                    <a
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary hover:underline"
                    >
                        {children}
                    </a>
                ),

                // Negrita y cursiva
                strong: ({ children }) => <strong className="font-semibold">{children}</strong>,
                em: ({ children }) => <em className="italic">{children}</em>,

                // Blockquotes
                blockquote: ({ children }) => (
                    <blockquote className="border-primary/30 bg-muted/30 my-2 border-l-4 py-1 pl-4 italic">
                        {children}
                    </blockquote>
                ),

                // Separadores
                hr: () => <hr className="border-border my-4" />,

                // Tablas (GFM)
                table: ({ children }) => (
                    <div className="my-2 overflow-x-auto">
                        <table className="border-border min-w-full border-collapse text-sm">
                            {children}
                        </table>
                    </div>
                ),
                thead: ({ children }) => <thead className="bg-muted/50">{children}</thead>,
                tbody: ({ children }) => <tbody>{children}</tbody>,
                tr: ({ children }) => <tr className="border-border border-b">{children}</tr>,
                th: ({ children }) => (
                    <th className="px-3 py-2 text-left font-semibold">{children}</th>
                ),
                td: ({ children }) => <td className="px-3 py-2">{children}</td>,
            }}
        >
            {content}
        </Markdown>
    );
}

// Detectar si el contenido contiene elementos pesados (cards, tablas grandes, URLs largas)
export function hasHeavyContent(content: string): boolean {
    // Detectar bloques ricos (cards) - resetear regex antes de usar
    RICH_BLOCK_REGEX.lastIndex = 0;
    if (RICH_BLOCK_REGEX.test(content)) {
        return true;
    }

    // Detectar tablas grandes (más de 5 filas)
    const tableRows = content.match(/\|.*\|/g);
    if (tableRows && tableRows.length > 5) {
        return true;
    }

    // Detectar múltiples URLs largas
    const urlPattern = /https?:\/\/[^\s\)]+/g;
    const urls = content.match(urlPattern);
    if (urls && urls.length > 2) {
        return true;
    }

    // Detectar bloques de código grandes (más de 20 líneas)
    const codeBlocks = content.match(/```[\s\S]*?```/g);
    if (codeBlocks) {
        for (const block of codeBlocks) {
            const lines = block.split('\n').length;
            if (lines > 20) {
                return true;
            }
        }
    }

    return false;
}

// Indicador de streaming más visible y profesional
function StreamingIndicator({ isHeavy }: { isHeavy: boolean }) {
    if (isHeavy) {
        return (
            <div className="mt-3 flex items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2">
                <div className="flex items-center gap-1">
                    <span className="bg-primary size-1.5 animate-pulse rounded-full [animation-delay:-0.3s]"></span>
                    <span className="bg-primary size-1.5 animate-pulse rounded-full [animation-delay:-0.15s]"></span>
                    <span className="bg-primary size-1.5 animate-pulse rounded-full"></span>
                </div>
                <span className="text-primary text-xs font-medium">Generando contenido...</span>
            </div>
        );
    }
    
    return (
        <span className="ml-1 inline-block h-4 w-0.5 animate-pulse rounded-full bg-primary align-middle" />
    );
}

export function MarkdownContent({ content, className, isStreaming = false }: MarkdownContentProps) {
    const parts = useMemo(() => parseContent(content), [content]);
    const hasHeavy = useMemo(() => hasHeavyContent(content), [content]);

    return (
        <div className={cn('prose prose-sm dark:prose-invert max-w-none min-w-0 overflow-hidden break-words', className)}>
            {parts.map((part, index) => {
                if (part.type === 'richBlock' && part.block) {
                    return <RichBlockRenderer key={index} block={part.block} />;
                }

                return <MarkdownRenderer key={index} content={part.content || ''} />;
            })}
            {isStreaming && <StreamingIndicator isHeavy={hasHeavy && content.length > 100} />}
        </div>
    );
}
