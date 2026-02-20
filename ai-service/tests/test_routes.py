"""
Tests for the AI Service API routes.
"""

import pytest
from unittest.mock import AsyncMock, patch, MagicMock


@pytest.mark.asyncio
async def test_health_endpoint(app_client):
    async with app_client as client:
        response = await client.get("/health")

    assert response.status_code == 200
    data = response.json()
    assert "status" in data
    assert data["service"] == "samsara-alert-ai"
    assert "concurrency" in data


@pytest.mark.asyncio
async def test_stats_endpoint(app_client):
    async with app_client as client:
        response = await client.get("/stats")

    assert response.status_code == 200
    data = response.json()
    assert "concurrency" in data
    assert "trace_id" in data


@pytest.mark.asyncio
async def test_health_returns_concurrency_stats(app_client):
    async with app_client as client:
        response = await client.get("/health")

    data = response.json()
    concurrency = data["concurrency"]
    assert "max_concurrent" in concurrency
    assert "active_requests" in concurrency
    assert "available_slots" in concurrency


@pytest.mark.asyncio
async def test_ingest_endpoint_with_valid_payload(app_client, sample_alert_payload, mock_pipeline_result):
    with patch("api.routes.PipelineExecutor") as MockExecutor:
        mock_executor = MagicMock()
        mock_executor.execute = AsyncMock(return_value=mock_pipeline_result)
        MockExecutor.return_value = mock_executor

        async with app_client as client:
            response = await client.post(
                "/alerts/ingest",
                json={"event_id": 42, "payload": sample_alert_payload},
            )

    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["event_id"] == 42
    assert "assessment" in data
    assert "human_message" in data


@pytest.mark.asyncio
async def test_ingest_returns_error_on_failure(app_client, sample_alert_payload, mock_failed_pipeline_result):
    with patch("api.routes.PipelineExecutor") as MockExecutor:
        mock_executor = MagicMock()
        mock_executor.execute = AsyncMock(return_value=mock_failed_pipeline_result)
        MockExecutor.return_value = mock_executor

        async with app_client as client:
            response = await client.post(
                "/alerts/ingest",
                json={"event_id": 42, "payload": sample_alert_payload},
            )

    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "error"


@pytest.mark.asyncio
async def test_ingest_returns_500_on_exception(app_client, sample_alert_payload):
    with patch("api.routes.PipelineExecutor") as MockExecutor:
        mock_executor = MagicMock()
        mock_executor.execute = AsyncMock(side_effect=Exception("Unexpected error"))
        MockExecutor.return_value = mock_executor

        async with app_client as client:
            response = await client.post(
                "/alerts/ingest",
                json={"event_id": 42, "payload": sample_alert_payload},
            )

    assert response.status_code == 500


@pytest.mark.asyncio
async def test_revalidate_endpoint(app_client, sample_alert_payload, sample_revalidation_context, mock_pipeline_result):
    with patch("api.routes.PipelineExecutor") as MockExecutor:
        mock_executor = MagicMock()
        mock_executor.execute = AsyncMock(return_value=mock_pipeline_result)
        MockExecutor.return_value = mock_executor

        async with app_client as client:
            response = await client.post(
                "/alerts/revalidate",
                json={
                    "event_id": 42,
                    "payload": sample_alert_payload,
                    "context": sample_revalidation_context,
                },
            )

    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"


@pytest.mark.asyncio
async def test_traceparent_header_propagated(app_client):
    traceparent = "00-abcdef1234567890abcdef1234567890-1234567890abcdef-01"

    async with app_client as client:
        response = await client.get("/health", headers={"traceparent": traceparent})

    assert "traceparent" in response.headers
    assert "x-trace-id" in response.headers
