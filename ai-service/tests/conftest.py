"""
Shared test fixtures for the AI Service test suite.
"""

import os
import sys
import pytest
from unittest.mock import AsyncMock, MagicMock, patch

# Ensure ai-service root is on the path so imports work
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

# Set required env vars before any application imports
os.environ.setdefault("OPENAI_API_KEY", "sk-test-key-for-testing")
os.environ.setdefault("ENVIRONMENT", "testing")
os.environ.setdefault("LOG_LEVEL", "WARNING")
os.environ.setdefault("LOG_FILE", "")
os.environ.setdefault("RATE_LIMITING_ENABLED", "false")


@pytest.fixture
def sample_alert_payload():
    """A realistic Samsara alert payload for testing."""
    return {
        "eventId": "evt_test_123",
        "eventType": "AlertIncident",
        "happenedAtTime": "2026-02-19T10:30:00Z",
        "company_id": 1,
        "vehicle": {"id": "123", "name": "T-001"},
        "data": {
            "alert": {"id": "alert_1", "name": "Test Alert", "severity": "critical"},
            "conditions": [
                {"type": "vehicle", "vehicleId": "123", "vehicleName": "T-001"}
            ],
        },
        "preloaded_data": {
            "vehicle_info": {"id": "123", "name": "T-001"},
            "driver_assignment": {"driver": {"id": "456", "name": "Test Driver"}},
            "vehicle_stats": [],
            "safety_events_correlation": [],
        },
    }


@pytest.fixture
def sample_revalidation_context():
    """Context payload for revalidation requests."""
    return {
        "investigation_count": 1,
        "previous_assessment": {
            "verdict": "uncertain",
            "likelihood": "medium",
            "confidence": 0.55,
            "requires_monitoring": True,
        },
        "investigation_history": [
            {
                "investigation_number": 1,
                "timestamp": "2026-02-19T10:00:00Z",
                "reason": "Initial monitoring",
            }
        ],
    }


@pytest.fixture
def mock_pipeline_result():
    """A successful PipelineResult mock."""
    from agents.schemas import PipelineResult

    return PipelineResult(
        success=True,
        assessment={
            "verdict": "confirmed_violation",
            "likelihood": "high",
            "confidence": 0.92,
            "reasoning": "Clear safety violation detected.",
            "recommended_actions": ["Notify supervisor"],
            "risk_escalation": "warn",
            "requires_monitoring": False,
        },
        alert_context={
            "alert_kind": "safety",
            "triage_notes": "Safety event detected.",
            "investigation_strategy": "Review vehicle data.",
            "proactive_flag": False,
            "investigation_plan": ["Check vehicle stats"],
        },
        human_message="Se detectó una violación de seguridad confirmada.",
        notification_decision={
            "should_notify": True,
            "escalation_level": 0,
            "message_text": "Alerta de seguridad.",
            "channels": ["sms", "whatsapp"],
            "recipients": [{"type": "monitoring_team", "priority": 1}],
        },
        notification_execution=None,
        agents=[],
        total_duration_ms=3500,
        total_tools_called=0,
        camera_analysis=None,
        error=None,
    )


@pytest.fixture
def mock_failed_pipeline_result():
    """A failed PipelineResult mock."""
    from agents.schemas import PipelineResult

    return PipelineResult(
        success=False,
        assessment=None,
        alert_context=None,
        human_message=None,
        notification_decision=None,
        notification_execution=None,
        agents=[],
        total_duration_ms=500,
        total_tools_called=0,
        camera_analysis=None,
        error="Pipeline execution failed: timeout",
    )


@pytest.fixture
def app_client():
    """FastAPI test client."""
    from httpx import AsyncClient, ASGITransport
    from main import app

    transport = ASGITransport(app=app)
    return AsyncClient(transport=transport, base_url="http://test")
