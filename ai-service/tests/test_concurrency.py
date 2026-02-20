"""
Tests for the concurrency control module.
"""

import asyncio
import os
import sys
import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

os.environ["RATE_LIMITING_ENABLED"] = "true"
os.environ["MAX_CONCURRENT_REQUESTS"] = "2"

from core.concurrency import (
    acquire_slot,
    get_concurrency_stats,
    ConcurrencyLimitExceeded,
)


def test_get_concurrency_stats():
    stats = get_concurrency_stats()

    assert "max_concurrent" in stats
    assert "active_requests" in stats
    assert "pending_requests" in stats
    assert "available_slots" in stats
    assert "rate_limiting_enabled" in stats


@pytest.mark.asyncio
async def test_acquire_and_release_slot():
    initial_stats = get_concurrency_stats()
    initial_active = initial_stats["active_requests"]

    async with acquire_slot():
        during_stats = get_concurrency_stats()
        assert during_stats["active_requests"] >= initial_active

    after_stats = get_concurrency_stats()
    assert after_stats["active_requests"] == initial_active


@pytest.mark.asyncio
async def test_concurrency_limit_exceeded():
    assert ConcurrencyLimitExceeded.__bases__[0] is Exception

    with pytest.raises(ConcurrencyLimitExceeded):
        raise ConcurrencyLimitExceeded("Service at capacity")
