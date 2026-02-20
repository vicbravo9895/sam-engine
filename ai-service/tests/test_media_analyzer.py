"""
Tests for the preloaded media analyzer (Vision AI).
"""

import os
import sys
import pytest
from unittest.mock import patch, AsyncMock, MagicMock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from services.preloaded_media_analyzer import analyze_preloaded_media


@pytest.mark.asyncio
async def test_analyzes_images_from_preloaded_data():
    mock_http_response = MagicMock()
    mock_http_response.status_code = 200
    mock_http_response.content = b"fake_image_data"

    mock_vision_response = MagicMock()
    mock_vision_response.choices = [
        MagicMock(
            message=MagicMock(
                content='{"scene_description": "Interior of truck", "alert_level": "NORMAL", "recommendation": {"action": "DESCARTAR", "reason": "No anomalies"}}'
            )
        )
    ]

    payload = {
        "preloaded_data": {
            "camera_media": {
                "items": [
                    {
                        "url": "https://example.com/image1.jpg",
                        "camera_type": "driver_facing",
                        "media_type": "image",
                        "captured_at": "2026-01-01T00:00:00Z",
                    }
                ]
            }
        }
    }

    with patch(
        "services.preloaded_media_analyzer.acompletion",
        new_callable=AsyncMock,
        return_value=mock_vision_response,
    ), patch(
        "services.preloaded_media_analyzer.httpx.AsyncClient",
    ) as mock_client_cls:
        mock_client = AsyncMock()
        mock_client.get = AsyncMock(return_value=mock_http_response)
        mock_client.__aenter__ = AsyncMock(return_value=mock_client)
        mock_client.__aexit__ = AsyncMock(return_value=False)
        mock_client_cls.return_value = mock_client

        result = await analyze_preloaded_media(payload)

    assert result is not None
    assert result["total_images_analyzed"] == 1
    assert len(result["analyses"]) == 1


@pytest.mark.asyncio
async def test_returns_none_when_no_camera_items():
    payload = {"preloaded_data": {"camera_media": {"items": []}}}
    result = await analyze_preloaded_media(payload)
    assert result is None


@pytest.mark.asyncio
async def test_returns_none_when_no_preloaded_data():
    payload = {}
    result = await analyze_preloaded_media(payload)
    assert result is None


@pytest.mark.asyncio
async def test_handles_revalidation_data():
    mock_http_response = MagicMock()
    mock_http_response.status_code = 200
    mock_http_response.content = b"fake_image_data"

    mock_vision_response = MagicMock()
    mock_vision_response.choices = [
        MagicMock(
            message=MagicMock(content='{"scene_description": "Road ahead"}')
        )
    ]

    payload = {
        "revalidation_data": {
            "camera_media_since_last_check": {
                "items": [
                    {
                        "url": "https://example.com/reval.jpg",
                        "camera_type": "front_facing",
                        "media_type": "image",
                    }
                ]
            }
        }
    }

    with patch(
        "services.preloaded_media_analyzer.acompletion",
        new_callable=AsyncMock,
        return_value=mock_vision_response,
    ), patch(
        "services.preloaded_media_analyzer.httpx.AsyncClient",
    ) as mock_client_cls:
        mock_client = AsyncMock()
        mock_client.get = AsyncMock(return_value=mock_http_response)
        mock_client.__aenter__ = AsyncMock(return_value=mock_client)
        mock_client.__aexit__ = AsyncMock(return_value=False)
        mock_client_cls.return_value = mock_client

        result = await analyze_preloaded_media(payload)

    assert result is not None
    assert result["source"] == "preloaded_data"
