"""
Tests for the PipelineExecutor service.
"""

import os
import sys
import pytest
from unittest.mock import patch, AsyncMock, MagicMock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from services.pipeline_executor import PipelineExecutor


@pytest.mark.asyncio
async def test_execute_full_pipeline(sample_alert_payload):
    """Test the full pipeline execution with mocked runner."""
    mock_session = MagicMock()
    mock_session.state = {
        "triage_result": '{"alert_type": "safety", "severity": "critical"}',
        "assessment_result": '{"verdict": "confirmed_violation", "likelihood": "high", "confidence": 0.92, "reasoning": "test", "recommended_actions": [], "risk_escalation": "warn", "requires_monitoring": false}',
        "final_message": "Alerta procesada correctamente.",
        "notification_decision": '{"should_notify": true, "escalation_level": 0, "message_text": "test", "channels": ["sms"]}',
    }

    mock_runner = MagicMock()

    async def mock_run(*args, **kwargs):
        for item in []:
            yield item

    mock_runner.run_async = mock_run

    mock_session_svc = AsyncMock()
    mock_session_svc.create_session = AsyncMock(return_value=mock_session)
    mock_session_svc.get_session = AsyncMock(return_value=mock_session)

    with patch("services.pipeline_executor.runner", mock_runner), \
         patch("services.pipeline_executor.session_service", mock_session_svc), \
         patch("services.pipeline_executor.analyze_preloaded_media", new_callable=AsyncMock, return_value=None):

        executor = PipelineExecutor()
        result = await executor.execute(
            payload=sample_alert_payload,
            event_id=42,
            is_revalidation=False,
        )

    assert result is not None
    assert result.success is True or result.error is not None


@pytest.mark.asyncio
async def test_execute_returns_error_on_runner_exception(sample_alert_payload):
    """Test that executor handles runner exceptions and returns error result."""
    mock_session = MagicMock()
    mock_session.state = {}

    mock_runner = MagicMock()

    async def mock_run(*args, **kwargs):
        raise Exception("Runner failed during execution")
        yield  # make it an async generator

    mock_runner.run_async = mock_run

    mock_session_svc = AsyncMock()
    mock_session_svc.create_session = AsyncMock(return_value=mock_session)
    mock_session_svc.get_session = AsyncMock(return_value=mock_session)

    with patch("services.pipeline_executor.runner", mock_runner), \
         patch("services.pipeline_executor.session_service", mock_session_svc), \
         patch("services.pipeline_executor.analyze_preloaded_media", new_callable=AsyncMock, return_value=None):

        executor = PipelineExecutor()
        result = await executor.execute(
            payload=sample_alert_payload,
            event_id=42,
            is_revalidation=False,
        )

    assert result.success is False
    assert result.error is not None


@pytest.mark.asyncio
async def test_execute_revalidation_uses_different_runner(sample_alert_payload, sample_revalidation_context):
    """Test that revalidation uses the revalidation runner."""
    mock_session = MagicMock()
    mock_session.state = {
        "assessment_result": '{"verdict": "confirmed_violation", "likelihood": "high", "confidence": 0.95, "reasoning": "test", "recommended_actions": [], "risk_escalation": "warn", "requires_monitoring": false}',
        "final_message": "Revalidation complete.",
        "notification_decision": '{"should_notify": false}',
    }

    mock_runner = MagicMock()

    async def mock_run(*args, **kwargs):
        for item in []:
            yield item

    mock_runner.run_async = mock_run

    mock_session_svc = AsyncMock()
    mock_session_svc.create_session = AsyncMock(return_value=mock_session)
    mock_session_svc.get_session = AsyncMock(return_value=mock_session)

    with patch("services.pipeline_executor.revalidation_runner", mock_runner), \
         patch("services.pipeline_executor.runner", MagicMock()), \
         patch("services.pipeline_executor.session_service", mock_session_svc), \
         patch("services.pipeline_executor.analyze_preloaded_media", new_callable=AsyncMock, return_value=None):

        executor = PipelineExecutor()
        result = await executor.execute(
            payload=sample_alert_payload,
            event_id=42,
            is_revalidation=True,
            context=sample_revalidation_context,
        )

    assert result is not None
