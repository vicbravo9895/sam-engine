import '@xyflow/react/dist/style.css';

import {
    addEdge,
    Background,
    BackgroundVariant,
    Controls,
    ReactFlow,
    useEdgesState,
    useNodesState,
    type Connection,
    type Edge,
    type Node,
    type OnEdgesChange,
    type OnNodesChange,
} from '@xyflow/react';
import { useCallback, useEffect, useMemo, useRef, type DragEvent } from 'react';
import { nodeTypes, type AndNodeData, type TriggerNodeData } from './flow-nodes';
import { SignalPalette } from './signal-palette';

export interface Rule {
    id: string;
    conditions: string[];
    action: string;
}

interface DetectionFlowProps {
    rules: Rule[];
    canonicalLabels: string[];
    labelTranslations: Record<string, string>;
    onChange: (rules: Rule[]) => void;
}

const NOTIFY_NODE_ID = 'notify-1';
const NOTIFY_X = 700;
const NOTIFY_Y = 200;
const TRIGGER_START_X = 50;
const AND_X = 420;
const ROW_HEIGHT = 110;
const TRIGGER_GAP_Y = 50;

function generateId() {
    return 'r-' + Math.random().toString(36).slice(2, 9);
}

function buildInitialGraph(
    rules: Rule[],
    labelTranslations: Record<string, string>,
) {
    const nodes: Node[] = [];
    const edges: Edge[] = [];

    nodes.push({
        id: NOTIFY_NODE_ID,
        type: 'notify',
        position: { x: NOTIFY_X, y: NOTIFY_Y },
        data: { label: 'Notificación' },
        draggable: true,
        deletable: false,
    });

    rules.forEach((rule, ruleIdx) => {
        const andId = `and-${rule.id}`;
        const andY = 60 + ruleIdx * ROW_HEIGHT;

        nodes.push({
            id: andId,
            type: 'and',
            position: { x: AND_X, y: andY },
            data: { ruleId: rule.id } satisfies AndNodeData,
            deletable: false,
        });

        edges.push({
            id: `e-${andId}-${NOTIFY_NODE_ID}`,
            source: andId,
            target: NOTIFY_NODE_ID,
            animated: true,
            style: { stroke: '#10b981' },
            deletable: false,
        });

        rule.conditions.forEach((label, condIdx) => {
            const triggerId = `trigger-${rule.id}-${label}`;
            const triggerY =
                andY -
                ((rule.conditions.length - 1) * TRIGGER_GAP_Y) / 2 +
                condIdx * TRIGGER_GAP_Y;

            nodes.push({
                id: triggerId,
                type: 'trigger',
                position: { x: TRIGGER_START_X, y: triggerY },
                data: {
                    label,
                    displayLabel: labelTranslations[label] ?? label,
                } satisfies TriggerNodeData,
                deletable: false,
            });

            edges.push({
                id: `e-${triggerId}-${andId}`,
                source: triggerId,
                target: andId,
                style: { stroke: '#8b5cf6' },
            });
        });
    });

    return { nodes, edges };
}

function deriveRulesFromGraph(nodes: Node[], edges: Edge[]): Rule[] {
    const andNodes = nodes.filter((n) => n.type === 'and');
    const rules: Rule[] = [];

    for (const andNode of andNodes) {
        const andData = andNode.data as AndNodeData;

        const hasNotifyEdge = edges.some(
            (e) => e.source === andNode.id && e.target === NOTIFY_NODE_ID,
        );
        if (!hasNotifyEdge) continue;

        const triggerEdges = edges.filter((e) => e.target === andNode.id);
        const conditions: string[] = [];

        for (const te of triggerEdges) {
            const triggerNode = nodes.find((n) => n.id === te.source && n.type === 'trigger');
            if (triggerNode) {
                const triggerData = triggerNode.data as TriggerNodeData;
                conditions.push(triggerData.label);
            }
        }

        if (conditions.length > 0) {
            rules.push({
                id: andData.ruleId ?? andNode.id,
                conditions,
                action: 'notify',
            });
        }
    }

    return rules;
}

export function DetectionFlow({
    rules,
    canonicalLabels,
    labelTranslations,
    onChange,
}: DetectionFlowProps) {
    const reactFlowWrapper = useRef<HTMLDivElement>(null);

    const initial = useMemo(
        () => buildInitialGraph(rules, labelTranslations),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const [nodes, setNodes, defaultOnNodesChange] = useNodesState(initial.nodes);
    const [edges, setEdges, defaultOnEdgesChange] = useEdgesState(initial.edges);

    // Delete a rule (AND + its triggers + all their edges)
    const deleteRule = useCallback(
        (andNodeId: string) => {
            setEdges((eds) => {
                const triggerIds = eds
                    .filter((e) => e.target === andNodeId)
                    .map((e) => e.source);

                setNodes((nds) =>
                    nds.filter((n) => n.id !== andNodeId && !triggerIds.includes(n.id)),
                );

                return eds.filter((e) => e.source !== andNodeId && e.target !== andNodeId);
            });
        },
        [setNodes, setEdges],
    );

    // Delete a single trigger node and its edges
    const deleteTrigger = useCallback(
        (triggerNodeId: string) => {
            setEdges((eds) => eds.filter((e) => e.source !== triggerNodeId));
            setNodes((nds) => nds.filter((n) => n.id !== triggerNodeId));
        },
        [setNodes, setEdges],
    );

    // Custom onNodesChange: block keyboard-delete on nodes, only allow position/selection changes
    const onNodesChange: OnNodesChange = useCallback(
        (changes) => {
            const filtered = changes.filter((c) => c.type !== 'remove');
            defaultOnNodesChange(filtered);
        },
        [defaultOnNodesChange],
    );

    // Custom onEdgesChange: allow edge removal freely (keeps nodes alive)
    const onEdgesChange: OnEdgesChange = useCallback(
        (changes) => {
            defaultOnEdgesChange(changes);
        },
        [defaultOnEdgesChange],
    );

    // Track used labels for the palette
    const usedLabels = useMemo(() => {
        const set = new Set<string>();
        nodes.forEach((n) => {
            if (n.type === 'trigger') {
                set.add((n.data as TriggerNodeData).label);
            }
        });
        return set;
    }, [nodes]);

    // Derive rules and propagate to parent
    const prevRulesJson = useRef(JSON.stringify(rules));
    useEffect(() => {
        const derived = deriveRulesFromGraph(nodes, edges);
        const json = JSON.stringify(derived);
        if (json !== prevRulesJson.current) {
            prevRulesJson.current = json;
            onChange(derived);
        }
    }, [nodes, edges, onChange]);

    const onConnect = useCallback(
        (connection: Connection) => {
            const sourceNode = nodes.find((n) => n.id === connection.source);
            const targetNode = nodes.find((n) => n.id === connection.target);
            if (!sourceNode || !targetNode) return;

            if (sourceNode.type === 'trigger' && targetNode.type === 'and') {
                // Prevent duplicate trigger→same-AND
                const alreadyConnected = edges.some(
                    (e) => e.source === connection.source && e.target === connection.target,
                );
                if (alreadyConnected) return;

                setEdges((eds) =>
                    addEdge({ ...connection, style: { stroke: '#8b5cf6' } }, eds),
                );
            } else if (sourceNode.type === 'and' && targetNode.type === 'notify') {
                setEdges((eds) =>
                    addEdge(
                        { ...connection, animated: true, style: { stroke: '#10b981' } },
                        eds,
                    ),
                );
            }
        },
        [nodes, edges, setEdges],
    );

    const isValidConnection = useCallback(
        (connection: Connection) => {
            const sourceNode = nodes.find((n) => n.id === connection.source);
            const targetNode = nodes.find((n) => n.id === connection.target);
            if (!sourceNode || !targetNode) return false;

            return (
                (sourceNode.type === 'trigger' && targetNode.type === 'and') ||
                (sourceNode.type === 'and' && targetNode.type === 'notify')
            );
        },
        [nodes],
    );

    // Drop from palette: create trigger + AND + edges as a new single-condition rule
    const onDragOver = useCallback((event: DragEvent) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }, []);

    const onDrop = useCallback(
        (event: DragEvent) => {
            event.preventDefault();
            const label = event.dataTransfer.getData('application/reactflow-label');
            if (!label) return;

            const ruleId = generateId();
            const andId = `and-${ruleId}`;
            const triggerId = `trigger-${ruleId}-${label}`;

            const bounds = reactFlowWrapper.current?.getBoundingClientRect();
            if (!bounds) return;
            const y = event.clientY - bounds.top;

            const newTrigger: Node = {
                id: triggerId,
                type: 'trigger',
                position: { x: TRIGGER_START_X, y: y - 15 },
                data: {
                    label,
                    displayLabel: labelTranslations[label] ?? label,
                } satisfies TriggerNodeData,
                deletable: false,
            };

            const newAnd: Node = {
                id: andId,
                type: 'and',
                position: { x: AND_X, y: y - 15 },
                data: { ruleId } satisfies AndNodeData,
                deletable: false,
            };

            setNodes((nds) => [...nds, newTrigger, newAnd]);
            setEdges((eds) => [
                ...eds,
                {
                    id: `e-${triggerId}-${andId}`,
                    source: triggerId,
                    target: andId,
                    style: { stroke: '#8b5cf6' },
                },
                {
                    id: `e-${andId}-${NOTIFY_NODE_ID}`,
                    source: andId,
                    target: NOTIFY_NODE_ID,
                    animated: true,
                    style: { stroke: '#10b981' },
                    deletable: false,
                },
            ]);
        },
        [setNodes, setEdges, labelTranslations],
    );

    return (
        <div className="flex overflow-hidden rounded-lg border" style={{ height: 480 }}>
            <SignalPalette
                labels={canonicalLabels}
                labelTranslations={labelTranslations}
                usedLabels={usedLabels}
            />
            <div ref={reactFlowWrapper} className="flex-1">
                <ReactFlow
                    nodes={nodes.map((n) => ({
                        ...n,
                        data: {
                            ...n.data,
                            ...(n.type === 'and' && { onDelete: deleteRule }),
                            ...(n.type === 'trigger' && { onDelete: deleteTrigger }),
                        },
                    }))}
                    edges={edges}
                    onNodesChange={onNodesChange}
                    onEdgesChange={onEdgesChange}
                    onConnect={onConnect}
                    isValidConnection={isValidConnection}
                    nodeTypes={nodeTypes}
                    onDrop={onDrop}
                    onDragOver={onDragOver}
                    fitView
                    fitViewOptions={{ padding: 0.3 }}
                    proOptions={{ hideAttribution: true }}
                    deleteKeyCode={['Backspace', 'Delete']}
                    className="bg-muted/30"
                >
                    <Background variant={BackgroundVariant.Dots} gap={16} size={1} />
                    <Controls showInteractive={false} />
                </ReactFlow>
            </div>
        </div>
    );
}
