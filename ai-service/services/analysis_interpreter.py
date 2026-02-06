"""
Servicio de interpretación LLM para análisis de flota.
Toma los resultados deterministas de un analizador y genera
insights ejecutivos/operativos en español usando GPT-4o-mini.
"""

import json
import logging
from typing import Any, Dict, List

from config import OpenAIConfig, langfuse_client

logger = logging.getLogger(__name__)

# ============================================================================
# SYSTEM PROMPT para la interpretación
# ============================================================================
INTERPRETER_SYSTEM_PROMPT = """
Eres un analista senior de operaciones de flotilla vehicular.

Tu trabajo es interpretar resultados de analisis de datos y generar insights
accionables en espanol para dueños y gerentes de flotas.

REGLAS:
1. Escribe en espanol SIN acentos (usa "vehiculo" no "vehículo")
2. Se conciso: maximo 4-6 parrafos cortos
3. Divide tu respuesta en dos secciones:
   - **Resumen Ejecutivo**: 2-3 oraciones para el dueño de la flota (alto nivel)
   - **Analisis Operativo**: 2-3 puntos especificos para el gerente de operaciones
4. Al final incluye 2-4 **Recomendaciones** concretas y accionables
5. Usa datos especificos del analisis (numeros, porcentajes, nombres)
6. NO uses markdown headers (##), usa **negritas** para subtitulos
7. NO inventes datos que no esten en el analisis
8. Si el riesgo es bajo y no hay hallazgos graves, dilo claramente y no exageres
9. Si hay hallazgos criticos, comunicalos con urgencia pero sin alarmar innecesariamente
""".strip()


async def interpret_analysis(
    analysis_type: str,
    title: str,
    summary: str,
    metrics: List[Dict[str, Any]],
    findings: List[Dict[str, Any]],
    risk_level: str,
    analysis_detail: Dict[str, Any],
    data_window: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Genera interpretación LLM de los resultados del análisis determinista.

    Args:
        analysis_type: Tipo de análisis ejecutado
        title: Título del análisis
        summary: Resumen de una línea
        metrics: Métricas clave
        findings: Hallazgos deterministas
        risk_level: Nivel de riesgo general
        analysis_detail: Datos adicionales del análisis
        data_window: Ventana temporal

    Returns:
        Dict con "insights" (str) y "recommendations" (list[str])
    """
    try:
        import litellm

        # Construir el prompt con los datos del análisis
        user_prompt = _build_user_prompt(
            analysis_type, title, summary, metrics,
            findings, risk_level, analysis_detail, data_window,
        )

        # Crear span de Langfuse si está disponible
        langfuse_kwargs = {}
        if langfuse_client:
            langfuse_kwargs["metadata"] = {
                "analysis_type": analysis_type,
                "risk_level": risk_level,
                "source": "analysis_interpreter",
            }

        # Llamar al LLM
        response = await litellm.acompletion(
            model=OpenAIConfig.MODEL_GPT4O_MINI,
            messages=[
                {"role": "system", "content": INTERPRETER_SYSTEM_PROMPT},
                {"role": "user", "content": user_prompt},
            ],
            temperature=0.3,
            max_tokens=1500,
            **langfuse_kwargs,
        )

        content = response.choices[0].message.content or ""

        # Extraer recomendaciones del texto generado
        insights, recommendations = _parse_response(content)

        logger.info("Analysis interpretation completed", extra={
            "context": {
                "analysis_type": analysis_type,
                "insights_length": len(insights),
                "recommendations_count": len(recommendations),
            }
        })

        return {
            "insights": insights,
            "recommendations": recommendations,
        }

    except Exception as e:
        logger.error(f"Error interpreting analysis: {e}", extra={
            "context": {
                "analysis_type": analysis_type,
                "error": str(e),
                "error_type": type(e).__name__,
            }
        })
        # Fallback: usar el summary como insight
        return {
            "insights": summary,
            "recommendations": [
                f.get("description", "") for f in findings[:3]
                if f.get("severity") in ("high", "critical")
            ],
        }


def _build_user_prompt(
    analysis_type: str,
    title: str,
    summary: str,
    metrics: List[Dict],
    findings: List[Dict],
    risk_level: str,
    analysis_detail: Dict,
    data_window: Dict,
) -> str:
    """Construye el prompt de usuario con los datos del análisis."""
    parts = []

    parts.append(f"## Analisis: {title}")
    parts.append(f"Tipo: {analysis_type}")
    parts.append(f"Resumen: {summary}")
    parts.append(f"Nivel de riesgo: {risk_level}")
    parts.append(f"Ventana de datos: {data_window.get('description', 'N/A')}")
    parts.append("")

    # Métricas
    parts.append("## Metricas Clave")
    for m in metrics:
        line = f"- {m.get('label', m.get('key', ''))}: {m.get('value', 'N/A')}"
        if m.get("unit"):
            line += f" {m['unit']}"
        if m.get("trend_value"):
            line += f" (tendencia: {m['trend_value']})"
        if m.get("severity"):
            line += f" [severidad: {m['severity']}]"
        parts.append(line)
    parts.append("")

    # Hallazgos
    parts.append("## Hallazgos")
    if findings:
        for f in findings:
            parts.append(f"- [{f.get('severity', 'low')}] {f.get('title', '')}: {f.get('description', '')}")
            if f.get("evidence"):
                for e in f["evidence"]:
                    parts.append(f"  - Evidencia: {e}")
    else:
        parts.append("- Sin hallazgos significativos")
    parts.append("")

    # Detalle adicional (resumido)
    if analysis_detail:
        parts.append("## Datos Adicionales")
        # Limitar tamaño para no exceder contexto
        detail_str = json.dumps(analysis_detail, ensure_ascii=False, default=str)
        if len(detail_str) > 2000:
            detail_str = detail_str[:2000] + "... (truncado)"
        parts.append(detail_str)

    parts.append("")
    parts.append("Genera tu interpretacion basandote en estos datos.")

    return "\n".join(parts)


def _parse_response(content: str) -> tuple:
    """
    Parsea la respuesta del LLM separando insights de recomendaciones.

    Returns:
        (insights: str, recommendations: list[str])
    """
    insights = content
    recommendations = []

    # Buscar sección de recomendaciones
    rec_markers = ["**Recomendaciones**", "**Recomendaciones:**", "Recomendaciones:"]
    for marker in rec_markers:
        if marker in content:
            parts = content.split(marker, 1)
            insights = parts[0].strip()
            rec_text = parts[1].strip()

            # Extraer items de la lista
            for line in rec_text.split("\n"):
                line = line.strip()
                if line.startswith(("-", "*", "•")):
                    clean = line.lstrip("-*• ").strip()
                    if clean:
                        recommendations.append(clean)
                elif line and line[0].isdigit() and "." in line[:4]:
                    clean = line.split(".", 1)[1].strip()
                    if clean:
                        recommendations.append(clean)
            break

    return insights, recommendations
