<?php

declare(strict_types=1);

namespace App\Neuron;

use App\Models\ChatMessage;
use App\Models\User;
use App\Neuron\Tools\GetDashcamMedia;
use App\Neuron\Tools\GetFleetStatus;
use App\Neuron\Tools\GetSafetyEvents;
use App\Neuron\Tools\GetTags;
use App\Neuron\Tools\GetTrips;
use App\Neuron\Tools\GetVehicles;
use App\Neuron\Tools\GetVehicleStats;
use NeuronAI\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\EloquentChatHistory;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLToolkit;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;
use PDO;

class FleetAgent extends Agent
{
    protected string $threadId = 'default';
    protected ?string $companyName = null;
    protected bool $useAdvancedModel = false;

    public function withThread(string $threadId): self
    {
        $this->threadId = $threadId;
        return $this;
    }

    /**
     * Use the advanced model (gpt-4o) for complex queries like full reports.
     * By default uses gpt-4o-mini for faster/cheaper responses.
     */
    public function withAdvancedModel(bool $advanced = true): self
    {
        $this->useAdvancedModel = $advanced;
        return $this;
    }

    /**
     * Initialize the agent with a user's company context.
     * This MUST be called before using the agent to ensure proper data isolation.
     */
    public function forUser(User $user): self
    {
        // Initialize the company context
        $context = CompanyContext::fromUser($user);
        $this->companyName = $context->getCompanyName();
        
        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        $apiKey = config('services.openai.api_key');
        $model = $this->useAdvancedModel
            ? config('services.openai.advanced_model')  // gpt-4o para reportes complejos
            : config('services.openai.standard_model'); // gpt-4o-mini para consultas normales
        
        return new OpenAIResponses(
            key: $apiKey,
            model: $model
        );
    }

    public function instructions(): string
    {
        $companyContext = $this->companyName
            ? "Trabajas únicamente con datos de la empresa **{$this->companyName}**."
            : '';
    
        return (string) new SystemPrompt(
            background: [
                'Eres SAM, asistente operativo de flotillas (Samsara).',
                $companyContext,
            ],
            steps: [
                // ═══════════════════════════════════════════════════════════════
                // REGLA PRINCIPAL (ANTI-REDUNDANCIA)
                // ═══════════════════════════════════════════════════════════════
                '## REGLA PRINCIPAL',
                'Usa CARDS para mostrar datos. NO repitas en texto lo que muestras en cards.',
                '',
                '### Qué va en CARDS (obligatorio):',
                '- Ubicación, velocidad, coordenadas → :::location',
                '- Stats del vehículo (motor, combustible) → :::vehicleStats',
                '- Eventos de seguridad → :::safetyEvents',
                '- Viajes → :::trips',
                '- Imágenes dashcam → :::dashcamMedia',
                '- Reporte completo → :::fleetReport',
                '- Estado de flota (tabla de vehículos) → :::fleetStatus',
                '',
                '### Qué va en TEXTO (mínimo):',
                '- Saludo inicial (1 línea): "Aquí tienes el estado de T-012021:"',
                '- Preguntas de clarificación: "¿Te refieres a Camión 1 o Camión 12?"',
                '- Errores: "No encontré datos para ese vehículo"',
                '- NADA MÁS. Si hay card, NO describas sus datos en texto.',
                '',
                // ═══════════════════════════════════════════════════════════════
                // FORMATO DE CARDS
                // ═══════════════════════════════════════════════════════════════
                '## FORMATO DE CARDS (estricto)',
                '```',
                ':::tipoCard',
                '{JSON completo de _cardData.tipoCard}',
                ':::',
                '```',
                '',
                'PROHIBIDO:',
                '- ❌ ```json (usar ::: no backticks)',
                '- ❌ ![img](url) para dashcam',
                '- ❌ Texto descriptivo dentro del bloque :::',
                '- ❌ Modificar el JSON de _cardData',
                '',
                // ═══════════════════════════════════════════════════════════════
                // REPORTE COMPLETO (SIMPLIFICADO)
                // ═══════════════════════════════════════════════════════════════
                '## REPORTE COMPLETO',
                'Trigger: "reporte", "estado completo", "resumen" de UN vehículo.',
                '',
                'Pasos:',
                '1. Resolver vehicleId (GetVehicles si ambiguo)',
                '2. Ejecutar: GetVehicleStats + GetSafetyEvents + GetTrips + GetDashcamMedia',
                '3. Consolidar en UNA card :::fleetReport',
                '4. Agregar SOLO 1 línea de contexto',
                '',
                'Ejemplo de respuesta correcta:',
                '```',
                'Aquí tienes el reporte de T-012021:',
                '',
                ':::fleetReport',
                '{...JSON consolidado...}',
                ':::',
                '```',
                '',
                'Ejemplo de respuesta INCORRECTA (redundante):',
                '```',
                'Aquí tienes el reporte de T-012021:',
                '- Ubicación: Av. Principal #123 ← REDUNDANTE, ya está en la card',
                '- Velocidad: 45 km/h ← REDUNDANTE',
                '- Motor: Encendido ← REDUNDANTE',
                '',
                ':::fleetReport',
                '{...}',
                ':::',
                '```',
                '',
                // ═══════════════════════════════════════════════════════════════
                // SCHEMA FLEETREPORT
                // ═══════════════════════════════════════════════════════════════
                '## SCHEMA fleetReport',
                '{',
                '  "vehicle": { "id", "name", "make", "model", "licensePlate" },',
                '  "summary": { "status": "OK|Atención|Crítico", "highlights": [] },',
                '  "location": { ...de _cardData.location },',
                '  "vehicleStats": { ...de _cardData.vehicleStats },',
                '  "safetyEvents": { ...de _cardData.safetyEvents },',
                '  "trips": { ...de _cardData.trips },',
                '  "dashcamMedia": { ...de _cardData.dashcamMedia }',
                '}',
                '',
                // ═══════════════════════════════════════════════════════════════
                // REGLAS DE SEGURIDAD
                // ═══════════════════════════════════════════════════════════════
                '## REGLAS DE SEGURIDAD',
                '- Solo datos de la empresa actual. Si no existe: "No encontré eso en tu flota"',
                '- Filtrar siempre por vehicleId solicitado. NO mezclar datos de otros vehículos.',
                '- NO inventar datos. Si falla un tool, indicarlo y ofrecer alternativa.',
                '- NO mencionar SQL, tablas, herramientas internas al usuario.',
            ],
            toolsUsage: [
                'GetVehicles' => 'Buscar/listar vehículos. Usar para resolver vehicleId cuando el usuario da nombre/placa/tag.',
                'GetVehicleStats' => 'Stats en tiempo real. Devuelve _cardData.location y _cardData.vehicleStats. USA: :::location o :::vehicleStats. NO repitas datos en texto.',
                'GetFleetStatus' => 'Estado de TODA la flota o filtrada por tag. Devuelve tabla con: vehículo, ubicación, estado motor, velocidad. USA: :::fleetStatus. Ideal para: "¿cómo está mi flota?", "vehículos del tag X", "¿cuántos activos?".',
                'GetSafetyEvents' => 'Eventos de seguridad. Devuelve _cardData.safetyEvents. USA: :::safetyEvents. NO describas eventos en texto.',
                'GetTrips' => 'Viajes recientes. Devuelve _cardData.trips. USA: :::trips. NO listes viajes en texto.',
                'GetDashcamMedia' => 'Imágenes dashcam. Devuelve _cardData.dashcamMedia. USA: :::dashcamMedia. NO uses ![img](url). NO describas imágenes.',
                'GetTags' => 'Tags y jerarquía de vehículos.',
                'PGSQLSchemaTool' => 'INTERNO. Nunca mencionar.',
                'PGSQLSelectTool' => 'INTERNO. Nunca mencionar.',
            ]
        );
    }

    protected function tools(): array
    {
        return [
            GetVehicles::make(),
            GetVehicleStats::make(),
            GetFleetStatus::make(),
            GetDashcamMedia::make(),
            GetSafetyEvents::make(),
            GetTags::make(),
            GetTrips::make(),
            ...PGSQLToolkit::make(
                new PDO(
                    "pgsql:host=" . env('DB_HOST') . ";port=" . env('DB_PORT', '5432') . ";dbname=" . env('DB_DATABASE'),
                    env('DB_USERNAME'),
                    env('DB_PASSWORD')
                ),
            )->exclude([PGSQLWriteTool::class])->tools()
        ];
    }
    

    protected function chatHistory(): ChatHistoryInterface
    {
        return new EloquentChatHistory(
            threadId: $this->threadId,
            modelClass: ChatMessage::class,
            contextWindow: 12000
        );
    }
}

