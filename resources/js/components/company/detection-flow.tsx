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
import { useCallback, useEffect, useMemo, useRef, type DragEvent, type RefObject } from 'react';
import {
    ACTION_TYPES,
    CHANNEL_TYPES,
    RECIPIENT_TYPES,
    nextAction,
    nodeTypes,
    type ActionType,
    type AndNodeData,
    type ChannelNodeData,
    type ChannelType,
    type RecipientNodeData,
    type RecipientType,
    type TriggerNodeData,
} from './flow-nodes';
import { SignalPalette } from './signal-palette';

export interface Rule {
    id: string;
    conditions: string[];
    action: string;
    channels: string[];
    recipients: string[];
}

interface DetectionFlowProps {
    rules: Rule[];
    canonicalLabels: string[];
    labelTranslations: Record<string, string>;
    onChange: (rules: Rule[]) => void;
    height?: number;
}

const TRIGGER_START_X = 50;
const AND_X = 420;
const OUTPUT_X = 700;
const ROW_HEIGHT = 110;
const TRIGGER_GAP_Y = 50;
const OUTPUT_GAP_Y = 45;

function generateId() {
    return 'r-' + Math.random().toString(36).slice(2, 9);
}

function buildInitialGraph(rules: Rule[], labelTranslations: Record<string, string>) {
    const nodes: Node[] = [];
    const edges: Edge[] = [];

    rules.forEach((rule, ruleIdx) => {
        const andId = `and-${rule.id}`;
        const andY = 60 + ruleIdx * ROW_HEIGHT;

        nodes.push({
            id: andId,
            type: 'and',
            position: { x: AND_X, y: andY },
            data: {
                ruleId: rule.id,
                action: (rule.action in ACTION_TYPES ? rule.action : 'ai_pipeline') as ActionType,
            } satisfies AndNodeData,
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

        const outputs = [
            ...(rule.channels ?? []).map((ch) => ({ type: 'channel' as const, key: ch })),
            ...(rule.recipients ?? []).map((r) => ({ type: 'recipient' as const, key: r })),
        ];

        outputs.forEach((out, outIdx) => {
            const outY =
                andY - ((outputs.length - 1) * OUTPUT_GAP_Y) / 2 + outIdx * OUTPUT_GAP_Y;

            if (out.type === 'channel') {
                const nodeId = `channel-${rule.id}-${out.key}`;
                const ch = out.key as ChannelType;
                nodes.push({
                    id: nodeId,
                    type: 'channel',
                    position: { x: OUTPUT_X, y: outY },
                    data: {
                        channel: ch,
                        displayLabel: CHANNEL_TYPES[ch]?.label ?? ch,
                    } satisfies ChannelNodeData,
                    deletable: false,
                });
                edges.push({
                    id: `e-${andId}-${nodeId}`,
                    source: andId,
                    target: nodeId,
                    style: { stroke: '#0ea5e9' },
                });
            } else {
                const nodeId = `recipient-${rule.id}-${out.key}`;
                const rType = out.key as RecipientType;
                nodes.push({
                    id: nodeId,
                    type: 'recipient',
                    position: { x: OUTPUT_X, y: outY },
                    data: {
                        recipientType: rType,
                        displayLabel: RECIPIENT_TYPES[rType]?.label ?? out.key,
                    } satisfies RecipientNodeData,
                    deletable: false,
                });
                edges.push({
                    id: `e-${andId}-${nodeId}`,
                    source: andId,
                    target: nodeId,
                    style: { stroke: '#14b8a6' },
                });
            }
        });
    });

    return { nodes, edges };
}

function deriveRulesFromGraph(nodes: Node[], edges: Edge[]): Rule[] {
    const andNodes = nodes.filter((n) => n.type === 'and');
    const rules: Rule[] = [];

    for (const andNode of andNodes) {
        const d = andNode.data as AndNodeData;

        const triggerEdges = edges.filter((e) => e.target === andNode.id);
        const conditions: string[] = [];

        for (const te of triggerEdges) {
            const triggerNode = nodes.find((n) => n.id === te.source && n.type === 'trigger');
            if (triggerNode) {
                conditions.push((triggerNode.data as TriggerNodeData).label);
            }
        }

        const outputEdges = edges.filter((e) => e.source === andNode.id);
        const channels: string[] = [];
        const recipients: string[] = [];

        for (const oe of outputEdges) {
            const targetNode = nodes.find((n) => n.id === oe.target);
            if (!targetNode) continue;

            if (targetNode.type === 'channel') {
                channels.push((targetNode.data as ChannelNodeData).channel);
            } else if (targetNode.type === 'recipient') {
                recipients.push((targetNode.data as RecipientNodeData).recipientType);
            }
        }

        if (conditions.length > 0) {
            rules.push({
                id: d.ruleId ?? andNode.id,
                conditions,
                action: d.action ?? 'ai_pipeline',
                channels,
                recipients,
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
    height = 480,
}: DetectionFlowProps) {
    const reactFlowWrapper = useRef<HTMLDivElement>(null);

    const initial = useMemo(
        () => buildInitialGraph(rules, labelTranslations),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const [nodes, setNodes, defaultOnNodesChange] = useNodesState(initial.nodes);
    const [edges, setEdges, defaultOnEdgesChange] = useEdgesState(initial.edges);

    // --- mutations ---

    const deleteRule = useCallback(
        (andNodeId: string) => {
            setEdges((eds) => {
                const triggerIds = eds
                    .filter((e) => e.target === andNodeId)
                    .map((e) => e.source);

                const outputIds = eds
                    .filter((e) => e.source === andNodeId)
                    .map((e) => e.target);

                setNodes((nds) =>
                    nds.filter(
                        (n) =>
                            n.id !== andNodeId &&
                            !triggerIds.includes(n.id) &&
                            !outputIds.includes(n.id),
                    ),
                );

                return eds.filter((e) => e.source !== andNodeId && e.target !== andNodeId);
            });
        },
        [setNodes, setEdges],
    );

    const deleteTrigger = useCallback(
        (triggerNodeId: string) => {
            setEdges((eds) => eds.filter((e) => e.source !== triggerNodeId));
            setNodes((nds) => nds.filter((n) => n.id !== triggerNodeId));
        },
        [setNodes, setEdges],
    );

    const deleteOutputNode = useCallback(
        (nodeId: string) => {
            setEdges((eds) => eds.filter((e) => e.target !== nodeId));
            setNodes((nds) => nds.filter((n) => n.id !== nodeId));
        },
        [setNodes, setEdges],
    );

    const cycleAction = useCallback(
        (andNodeId: string) => {
            setNodes((nds) =>
                nds.map((n) => {
                    if (n.id !== andNodeId) return n;
                    const d = n.data as AndNodeData;
                    return {
                        ...n,
                        data: { ...d, action: nextAction(d.action ?? 'ai_pipeline') },
                    };
                }),
            );
        },
        [setNodes],
    );

    const addAndNode = useCallback(() => {
        const ruleId = generateId();
        const andId = `and-${ruleId}`;
        const existingAnds = nodes.filter((n) => n.type === 'and');
        const y = 60 + existingAnds.length * ROW_HEIGHT;

        setNodes((nds) => [
            ...nds,
            {
                id: andId,
                type: 'and',
                position: { x: AND_X, y },
                data: { ruleId, action: 'ai_pipeline' as ActionType } satisfies AndNodeData,
                deletable: false,
            },
        ]);
    }, [setNodes, nodes]);

    // --- react-flow handlers ---

    const onNodesChange: OnNodesChange = useCallback(
        (changes) => {
            defaultOnNodesChange(changes.filter((c) => c.type !== 'remove'));
        },
        [defaultOnNodesChange],
    );

    const onEdgesChange: OnEdgesChange = useCallback(
        (changes) => defaultOnEdgesChange(changes),
        [defaultOnEdgesChange],
    );

    const onConnect = useCallback(
        (connection: Connection) => {
            const src = nodes.find((n) => n.id === connection.source);
            const tgt = nodes.find((n) => n.id === connection.target);
            if (!src || !tgt) return;

            const isTriggerToAnd = src.type === 'trigger' && tgt.type === 'and';
            const isAndToOutput =
                src.type === 'and' && (tgt.type === 'channel' || tgt.type === 'recipient');

            if (!isTriggerToAnd && !isAndToOutput) return;

            const alreadyConnected = edges.some(
                (e) => e.source === connection.source && e.target === connection.target,
            );
            if (alreadyConnected) return;

            const strokeColor = isTriggerToAnd
                ? '#8b5cf6'
                : tgt.type === 'channel'
                  ? '#0ea5e9'
                  : '#14b8a6';

            setEdges((eds) =>
                addEdge({ ...connection, style: { stroke: strokeColor } }, eds),
            );
        },
        [nodes, edges, setEdges],
    );

    const isValidConnection = useCallback(
        (connection: Connection) => {
            const src = nodes.find((n) => n.id === connection.source);
            const tgt = nodes.find((n) => n.id === connection.target);
            if (!src || !tgt) return false;

            if (src.type === 'trigger' && tgt.type === 'and') return true;
            if (src.type === 'and' && (tgt.type === 'channel' || tgt.type === 'recipient'))
                return true;

            return false;
        },
        [nodes],
    );

    // --- drop from palette ---

    const onDragOver = useCallback((event: DragEvent) => {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
    }, []);

    const onDrop = useCallback(
        (event: DragEvent) => {
            event.preventDefault();

            const bounds = reactFlowWrapper.current?.getBoundingClientRect();
            if (!bounds) return;
            const y = event.clientY - bounds.top;

            const label = event.dataTransfer.getData('application/reactflow-label');
            if (label) {
                const triggerId = `trigger-${generateId()}-${label}`;
                setNodes((nds) => [
                    ...nds,
                    {
                        id: triggerId,
                        type: 'trigger',
                        position: { x: TRIGGER_START_X, y: y - 15 },
                        data: {
                            label,
                            displayLabel: labelTranslations[label] ?? label,
                        } satisfies TriggerNodeData,
                        deletable: false,
                    } as Node,
                ]);
                return;
            }

            const channel = event.dataTransfer.getData('application/reactflow-channel');
            if (channel) {
                const ch = channel as ChannelType;
                const nodeId = `channel-${generateId()}-${channel}`;
                setNodes((nds) => [
                    ...nds,
                    {
                        id: nodeId,
                        type: 'channel',
                        position: { x: OUTPUT_X, y: y - 15 },
                        data: {
                            channel: ch,
                            displayLabel: CHANNEL_TYPES[ch]?.label ?? channel,
                        } satisfies ChannelNodeData,
                        deletable: false,
                    } as Node,
                ]);
                return;
            }

            const recipient = event.dataTransfer.getData('application/reactflow-recipient');
            if (recipient) {
                const rType = recipient as RecipientType;
                const nodeId = `recipient-${generateId()}-${recipient}`;
                setNodes((nds) => [
                    ...nds,
                    {
                        id: nodeId,
                        type: 'recipient',
                        position: { x: OUTPUT_X, y: y - 15 },
                        data: {
                            recipientType: rType,
                            displayLabel: RECIPIENT_TYPES[rType]?.label ?? recipient,
                        } satisfies RecipientNodeData,
                        deletable: false,
                    } as Node,
                ]);
            }
        },
        [setNodes, labelTranslations],
    );

    // --- propagate rules to parent ---

    const onChangeRef: RefObject<DetectionFlowProps['onChange']> = useRef(onChange);
    onChangeRef.current = onChange;

    const prevRulesJson = useRef(JSON.stringify(rules));
    useEffect(() => {
        const derived = deriveRulesFromGraph(nodes, edges);
        const json = JSON.stringify(derived);
        if (json !== prevRulesJson.current) {
            prevRulesJson.current = json;
            onChangeRef.current(derived);
        }
    }, [nodes, edges]);

    // --- render ---

    const enrichedNodes = nodes.map((n) => ({
        ...n,
        data: {
            ...n.data,
            ...(n.type === 'and' && {
                onDelete: deleteRule,
                onCycleAction: cycleAction,
            }),
            ...(n.type === 'trigger' && { onDelete: deleteTrigger }),
            ...((n.type === 'channel' || n.type === 'recipient') && {
                onDelete: deleteOutputNode,
            }),
        },
    }));

    return (
        <div className="flex overflow-hidden rounded-lg border" style={{ height }}>
            <SignalPalette
                labels={canonicalLabels}
                labelTranslations={labelTranslations}
                onAddAndNode={addAndNode}
            />
            <div ref={reactFlowWrapper} className="flex-1">
                <ReactFlow
                    nodes={enrichedNodes}
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
