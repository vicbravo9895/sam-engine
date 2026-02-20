"""
Tests for Pydantic schema validation.
"""

import os
import sys
import pytest
from pydantic import ValidationError

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


def test_alert_request_valid():
    from api.models import AlertRequest

    req = AlertRequest(event_id=42, payload={"eventType": "AlertIncident"})
    assert req.event_id == 42
    assert req.payload["eventType"] == "AlertIncident"


def test_alert_request_missing_event_id():
    from api.models import AlertRequest

    with pytest.raises(ValidationError):
        AlertRequest(payload={"eventType": "AlertIncident"})


def test_alert_request_missing_payload():
    from api.models import AlertRequest

    with pytest.raises(ValidationError):
        AlertRequest(event_id=42)


def test_pipeline_result_success():
    from agents.schemas import PipelineResult

    result = PipelineResult(
        success=True,
        assessment={"verdict": "confirmed_violation"},
        alert_context={"alert_kind": "safety"},
        human_message="Test message",
        notification_decision=None,
        notification_execution=None,
        agents=[],
        total_duration_ms=1000,
        total_tools_called=0,
        camera_analysis=None,
        error=None,
    )

    assert result.success is True
    assert result.assessment["verdict"] == "confirmed_violation"


def test_pipeline_result_error():
    from agents.schemas import PipelineResult

    result = PipelineResult(
        success=False,
        assessment=None,
        alert_context=None,
        human_message=None,
        notification_decision=None,
        notification_execution=None,
        agents=[],
        total_duration_ms=200,
        total_tools_called=0,
        camera_analysis=None,
        error="Pipeline failed",
    )

    assert result.success is False
    assert result.error == "Pipeline failed"


def test_agent_result():
    from agents.schemas import AgentResult

    agent = AgentResult(
        name="triage_agent",
        duration_ms=500,
        summary="Triage completed",
    )
    assert agent.name == "triage_agent"
    assert agent.duration_ms == 500


def test_tool_result():
    from agents.schemas import ToolResult

    tool = ToolResult(
        name="get_vehicle_stats",
        duration_ms=200,
        summary="Vehicle stats retrieved",
        media_urls=["https://example.com/image.jpg"],
    )
    assert tool.name == "get_vehicle_stats"
    assert len(tool.media_urls) == 1


def test_health_response():
    from api.models import HealthResponse

    resp = HealthResponse(
        status="healthy",
        service="samsara-alert-ai",
        timestamp="2026-02-19T10:00:00",
        concurrency={"active_requests": 0, "max_concurrent": 5},
    )
    assert resp.status == "healthy"
