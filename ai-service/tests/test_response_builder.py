"""
Tests for AlertResponseBuilder and encoding utilities.
"""

import pytest
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from services.response_builder import (
    AlertResponseBuilder,
    _fix_corrupted_encoding,
    _fix_dict_encoding,
    _clean_markdown,
    _ensure_object,
    _ensure_string,
)
from agents.schemas import PipelineResult


def test_fix_corrupted_encoding_spanish():
    assert _fix_corrupted_encoding("informaci22n") == "información"
    assert _fix_corrupted_encoding("evaluaci33n") == "evaluación"
    assert _fix_corrupted_encoding("bot22n") == "botón"


def test_fix_corrupted_encoding_preserves_clean_text():
    clean = "Texto limpio sin problemas"
    assert _fix_corrupted_encoding(clean) == clean


def test_fix_corrupted_encoding_handles_none():
    assert _fix_corrupted_encoding(None) is None
    assert _fix_corrupted_encoding("") == ""


def test_fix_dict_encoding_recursive():
    data = {
        "message": "informaci22n importante",
        "nested": {
            "description": "evaluaci33n positiva",
        },
        "list": ["acci22n requerida"],
    }
    fixed = _fix_dict_encoding(data)
    assert fixed["message"] == "información importante"
    assert fixed["nested"]["description"] == "evaluación positiva"
    assert fixed["list"][0] == "acción requerida"


def test_clean_markdown_json_block():
    text = '```json\n{"key": "value"}\n```'
    assert _clean_markdown(text) == '{"key": "value"}'


def test_clean_markdown_no_block():
    text = '{"key": "value"}'
    assert _clean_markdown(text) == '{"key": "value"}'


def test_ensure_object_with_dict():
    data = {"verdict": "confirmed"}
    assert _ensure_object(data) == data


def test_ensure_object_with_json_string():
    result = _ensure_object('{"verdict": "confirmed"}')
    assert result == {"verdict": "confirmed"}


def test_ensure_object_with_none():
    assert _ensure_object(None) is None


def test_ensure_object_with_invalid_string():
    assert _ensure_object("not json") is None


def test_ensure_string_with_dict():
    result = _ensure_string({"key": "value"})
    assert isinstance(result, str)


def test_ensure_string_with_none():
    assert _ensure_string(None) == ""


def test_build_success_response(mock_pipeline_result):
    response = AlertResponseBuilder.build(mock_pipeline_result, event_id=42)

    assert response["status"] == "success"
    assert response["event_id"] == 42
    assert isinstance(response["assessment"], dict)
    assert isinstance(response["human_message"], str)
    assert isinstance(response["alert_context"], dict)
    assert isinstance(response["notification_decision"], dict)
    assert isinstance(response["execution"], dict)


def test_build_error_response(mock_failed_pipeline_result):
    response = AlertResponseBuilder.build(mock_failed_pipeline_result, event_id=42)

    assert response["status"] == "error"
    assert response["event_id"] == 42
    assert "error" in response


def test_build_error_static():
    response = AlertResponseBuilder.build_error(42, "Something went wrong")

    assert response["status"] == "error"
    assert response["event_id"] == 42
    assert response["error"] == "Something went wrong"


def test_extract_for_laravel(mock_pipeline_result):
    response = AlertResponseBuilder.build(mock_pipeline_result, event_id=42)
    extracted = AlertResponseBuilder.extract_for_laravel(response)

    assert extracted["ai_status"] == "completed"
    assert isinstance(extracted["ai_assessment"], dict)
    assert isinstance(extracted["ai_message"], str)


def test_build_includes_camera_analysis():
    from agents.schemas import PipelineResult

    result = PipelineResult(
        success=True,
        assessment={"verdict": "confirmed_violation"},
        alert_context={},
        human_message="Test",
        notification_decision=None,
        notification_execution=None,
        agents=[],
        total_duration_ms=1000,
        total_tools_called=0,
        camera_analysis={
            "total_images_analyzed": 2,
            "analyses": [
                {"samsara_url": "https://img1.example.com", "description": "Clear image"},
                {"samsara_url": "https://img2.example.com", "description": "Night image"},
            ],
        },
        error=None,
    )

    response = AlertResponseBuilder.build(result, event_id=1)

    assert "camera_analysis" in response
    assert response["camera_analysis"]["total_images"] == 2
    assert len(response["camera_analysis"]["media_urls"]) == 2


def test_build_defaults_notification_decision():
    from agents.schemas import PipelineResult

    result = PipelineResult(
        success=True,
        assessment={"verdict": "no_action_needed"},
        alert_context={},
        human_message="No action required.",
        notification_decision=None,
        notification_execution=None,
        agents=[],
        total_duration_ms=1000,
        total_tools_called=0,
        camera_analysis=None,
        error=None,
    )

    response = AlertResponseBuilder.build(result, event_id=1)

    assert response["notification_decision"]["should_notify"] is False
