"""
AI Insight Generator for Safety Signals.

Uses GPT to generate natural language insights and recommendations
based on patterns, risk scores, and predictions.
"""

import json
import uuid
from datetime import datetime
from typing import Optional

from openai import AsyncOpenAI

from api.analytics_models import (
    PatternResult,
    DriverRiskScore,
    PredictionResult,
    Insight,
    InsightResponse,
)
from config.settings import OpenAIConfig


INSIGHT_SYSTEM_PROMPT = """Eres un analista experto en seguridad de flotas vehiculares.
Tu trabajo es analizar datos de safety signals (eventos de seguridad detectados en vehículos)
y generar insights accionables en español para operadores de flota.

Debes generar entre 3 y 5 insights basados en los datos proporcionados.
Cada insight debe tener:
- Una categoría: "pattern" (patrones detectados), "risk" (riesgos identificados), 
  "prediction" (predicciones), o "recommendation" (recomendaciones de acción)
- Una prioridad: "low", "medium", o "high"
- Un título corto y claro
- Una descripción detallada pero concisa (2-3 oraciones)
- Puntos de datos específicos que respaldan el insight
- Acciones concretas a tomar (si aplica)

Enfócate en:
1. Patrones preocupantes que requieren atención
2. Conductores de alto riesgo y qué hacer con ellos
3. Tendencias temporales significativas
4. Recomendaciones concretas y accionables

Responde SOLO con un JSON array de insights, sin texto adicional.
Ejemplo de formato:
[
  {
    "category": "pattern",
    "priority": "high",
    "title": "Concentración de eventos en horario nocturno",
    "description": "El 45% de los eventos críticos ocurren entre las 22:00 y 06:00...",
    "data_points": ["65 eventos nocturnos", "23% más que turno diurno"],
    "action_items": ["Revisar política de turnos nocturnos", "Capacitación específica"]
  }
]"""


class AIInsightGenerator:
    """Generates natural language insights using GPT."""

    def __init__(self):
        self.client = AsyncOpenAI(api_key=OpenAIConfig.API_KEY)
        self.model = "gpt-4o-mini"  # Use mini for cost efficiency

    async def generate_insights(
        self,
        summary: dict,
        patterns: Optional[PatternResult] = None,
        risk_scores: Optional[list[DriverRiskScore]] = None,
        predictions: Optional[PredictionResult] = None,
    ) -> InsightResponse:
        """Generate AI-powered insights based on analytics data."""
        
        # Prepare context for the model
        context = self._prepare_context(summary, patterns, risk_scores, predictions)
        
        try:
            response = await self.client.chat.completions.create(
                model=self.model,
                messages=[
                    {"role": "system", "content": INSIGHT_SYSTEM_PROMPT},
                    {"role": "user", "content": context},
                ],
                temperature=0.7,
                max_tokens=1500,
            )

            content = response.choices[0].message.content or "[]"
            insights = self._parse_insights(content)

            return InsightResponse(
                insights=insights,
                generated_at=datetime.utcnow().isoformat() + "Z",
                model_used=self.model,
            )

        except Exception as e:
            # Return fallback insights on error
            return InsightResponse(
                insights=self._generate_fallback_insights(summary, patterns, risk_scores),
                generated_at=datetime.utcnow().isoformat() + "Z",
                model_used="fallback",
            )

    def _prepare_context(
        self,
        summary: dict,
        patterns: Optional[PatternResult],
        risk_scores: Optional[list[DriverRiskScore]],
        predictions: Optional[PredictionResult],
    ) -> str:
        """Prepare context string for the AI model."""
        context_parts = []

        # Summary statistics
        context_parts.append("## Resumen de Estadísticas")
        context_parts.append(f"- Total de señales: {summary.get('total_signals', 0)}")
        context_parts.append(f"- Críticas: {summary.get('critical', 0)} ({summary.get('critical_rate', 0)}%)")
        context_parts.append(f"- Necesitan revisión: {summary.get('needs_review', 0)}")
        context_parts.append(f"- Promedio diario: {summary.get('avg_daily', 0)}")
        context_parts.append(f"- Conductores únicos: {summary.get('unique_drivers', 0)}")
        context_parts.append(f"- Vehículos únicos: {summary.get('unique_vehicles', 0)}")

        # Patterns
        if patterns:
            context_parts.append("\n## Patrones Detectados")
            
            if patterns.behavior_correlations:
                context_parts.append("\n### Correlaciones de Comportamiento")
                for corr in patterns.behavior_correlations[:5]:
                    context_parts.append(
                        f"- {corr.behavior_a} ↔ {corr.behavior_b}: correlación {corr.correlation:.2f} ({corr.co_occurrence_count} co-ocurrencias)"
                    )

            if patterns.temporal_hotspots:
                context_parts.append("\n### Hotspots Temporales")
                for hotspot in patterns.temporal_hotspots[:5]:
                    context_parts.append(
                        f"- {hotspot.value}: {hotspot.signal_count} señales, riesgo {hotspot.risk_level}"
                    )

            if patterns.escalation_patterns:
                context_parts.append("\n### Patrones de Escalación")
                for esc in patterns.escalation_patterns[:5]:
                    context_parts.append(
                        f"- {esc.driver_name or esc.driver_id}: {esc.escalation_rate*100:.0f}% críticos, tendencia {esc.trend}"
                    )

        # Risk scores
        if risk_scores:
            context_parts.append("\n## Conductores de Alto Riesgo")
            for driver in risk_scores[:5]:
                context_parts.append(
                    f"- {driver.driver_name or driver.driver_id}: score {driver.risk_score:.0f}/100, "
                    f"nivel {driver.risk_level}, tendencia {driver.trend}"
                )

        # Predictions
        if predictions:
            context_parts.append("\n## Predicciones")
            context_parts.append(
                f"- Pronóstico de volumen: {predictions.volume_forecast.current_avg_daily:.1f} → "
                f"{predictions.volume_forecast.predicted_avg_daily:.1f} (tendencia {predictions.volume_forecast.trend})"
            )
            
            if predictions.at_risk_drivers:
                context_parts.append("\n### Conductores en Riesgo de Incidente")
                for driver in predictions.at_risk_drivers[:3]:
                    context_parts.append(
                        f"- {driver.driver_name or driver.driver_id}: "
                        f"{driver.incident_probability*100:.0f}% probabilidad de incidente"
                    )

            if predictions.alerts:
                context_parts.append("\n### Alertas del Sistema")
                for alert in predictions.alerts:
                    context_parts.append(f"- {alert}")

        context_parts.append(
            "\n\nGenera insights accionables basados en estos datos. "
            "Prioriza los hallazgos más importantes y las recomendaciones más urgentes."
        )

        return "\n".join(context_parts)

    def _parse_insights(self, content: str) -> list[Insight]:
        """Parse JSON response from the model into Insight objects."""
        try:
            # Clean up response (remove markdown code blocks if present)
            content = content.strip()
            if content.startswith("```"):
                content = content.split("```")[1]
                if content.startswith("json"):
                    content = content[4:]
            content = content.strip()

            data = json.loads(content)
            
            insights = []
            for item in data:
                insights.append(
                    Insight(
                        id=str(uuid.uuid4())[:8],
                        category=item.get("category", "recommendation"),
                        priority=item.get("priority", "medium"),
                        title=item.get("title", "Insight"),
                        description=item.get("description", ""),
                        data_points=item.get("data_points", []),
                        action_items=item.get("action_items", []),
                    )
                )
            
            return insights

        except (json.JSONDecodeError, KeyError, TypeError):
            return []

    def _generate_fallback_insights(
        self,
        summary: dict,
        patterns: Optional[PatternResult],
        risk_scores: Optional[list[DriverRiskScore]],
    ) -> list[Insight]:
        """Generate basic insights without AI when GPT fails."""
        insights = []

        # Basic summary insight
        total = summary.get("total_signals", 0)
        critical = summary.get("critical", 0)
        if total > 0:
            critical_rate = (critical / total) * 100
            priority = "high" if critical_rate > 20 else "medium" if critical_rate > 10 else "low"
            
            insights.append(
                Insight(
                    id=str(uuid.uuid4())[:8],
                    category="pattern",
                    priority=priority,
                    title="Distribución de severidad",
                    description=f"De {total} señales analizadas, {critical} ({critical_rate:.1f}%) son críticas.",
                    data_points=[f"{total} señales totales", f"{critical_rate:.1f}% críticas"],
                    action_items=["Revisar eventos críticos pendientes"],
                )
            )

        # Risk score insight
        if risk_scores and len(risk_scores) > 0:
            high_risk = [d for d in risk_scores if d.risk_level in ("high", "critical")]
            if high_risk:
                insights.append(
                    Insight(
                        id=str(uuid.uuid4())[:8],
                        category="risk",
                        priority="high",
                        title=f"{len(high_risk)} conductores de alto riesgo",
                        description=f"Se identificaron {len(high_risk)} conductores con nivel de riesgo alto o crítico que requieren atención.",
                        data_points=[f"{d.driver_name}: score {d.risk_score:.0f}" for d in high_risk[:3]],
                        action_items=["Programar reuniones de coaching", "Revisar historial de eventos"],
                    )
                )

        # Pattern insight
        if patterns and patterns.temporal_hotspots:
            hotspot = patterns.temporal_hotspots[0]
            insights.append(
                Insight(
                    id=str(uuid.uuid4())[:8],
                    category="pattern",
                    priority=hotspot.risk_level,
                    title=f"Hotspot temporal: {hotspot.value}",
                    description=hotspot.description,
                    data_points=[f"{hotspot.signal_count} señales en este período"],
                    action_items=["Reforzar supervisión en horarios de alto riesgo"],
                )
            )

        return insights
