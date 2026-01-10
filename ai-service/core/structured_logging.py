"""
Structured JSON logging for AI Service.

This module provides a standardized JSON logging format compatible with
Grafana Loki and other log aggregation systems.

Standard fields:
- timestamp: ISO8601 format with timezone (UTC)
- level: Log level (info, error, warning, debug)
- service: Service name (ai-service)
- environment: Current environment (production, staging, local)
- trace_id: Unique request identifier for distributed tracing
- message: Log message
- context: Additional contextual data

Multi-tenant fields (when available):
- event_id: ID del evento siendo procesado
- company_id: ID de la empresa (extraído del payload)
"""

import json
import logging
import os
import sys
from contextvars import ContextVar
from datetime import datetime, timezone
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any, Optional

# Context variables (thread-safe for async)
trace_id_var: ContextVar[str] = ContextVar("trace_id", default="unknown")
event_id_var: ContextVar[Optional[int]] = ContextVar("event_id", default=None)
company_id_var: ContextVar[Optional[int]] = ContextVar("company_id", default=None)


def get_trace_id() -> str:
    """Get the current trace ID from context."""
    return trace_id_var.get()


def set_trace_id(trace_id: str) -> None:
    """Set the trace ID in context."""
    trace_id_var.set(trace_id)


def get_event_id() -> Optional[int]:
    """Get the current event ID from context."""
    return event_id_var.get()


def set_event_id(event_id: Optional[int]) -> None:
    """Set the event ID in context."""
    event_id_var.set(event_id)


def get_company_id() -> Optional[int]:
    """Get the current company ID from context."""
    return company_id_var.get()


def set_company_id(company_id: Optional[int]) -> None:
    """Set the company ID in context."""
    company_id_var.set(company_id)


def set_request_context(
    trace_id: str,
    event_id: Optional[int] = None,
    company_id: Optional[int] = None,
) -> None:
    """Set all request context variables at once."""
    set_trace_id(trace_id)
    set_event_id(event_id)
    set_company_id(company_id)


class JsonFormatter(logging.Formatter):
    """
    Custom JSON formatter for structured logging.
    
    Outputs logs in a standardized format compatible with Grafana Loki.
    """
    
    def __init__(self, service: str = "ai-service", environment: str = "production"):
        super().__init__()
        self.service = service
        self.environment = environment
    
    def format(self, record: logging.LogRecord) -> str:
        """Format log record as JSON."""
        # Build base log data
        log_data: dict[str, Any] = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname.lower(),
            "service": self.service,
            "environment": self.environment,
            "trace_id": get_trace_id(),
            "message": record.getMessage(),
        }
        
        # Add multi-tenant context if available
        event_id = get_event_id()
        if event_id is not None:
            log_data["event_id"] = event_id
            
        company_id = get_company_id()
        if company_id is not None:
            log_data["company_id"] = company_id
        
        # Add logger name if not root
        if record.name and record.name != "root":
            log_data["logger"] = record.name
        
        # Add context if present (custom attribute)
        context = getattr(record, "context", None)
        if context:
            log_data["context"] = self._sanitize_context(context)
        
        # Add exception info if present
        if record.exc_info:
            log_data["exception"] = {
                "type": record.exc_info[0].__name__ if record.exc_info[0] else "Unknown",
                "message": str(record.exc_info[1]) if record.exc_info[1] else "",
                "traceback": self.formatException(record.exc_info),
            }
        
        # Add source location for errors
        if record.levelno >= logging.ERROR:
            log_data["source"] = {
                "file": record.pathname,
                "line": record.lineno,
                "function": record.funcName,
            }
        
        return json.dumps(log_data, default=str, ensure_ascii=False)
    
    def _sanitize_context(self, context: Any) -> Any:
        """Sanitize context data to ensure JSON serializable."""
        if isinstance(context, dict):
            return {k: self._sanitize_context(v) for k, v in context.items()}
        elif isinstance(context, (list, tuple)):
            return [self._sanitize_context(item) for item in context]
        elif isinstance(context, (str, int, float, bool, type(None))):
            return context
        elif hasattr(context, "dict"):  # Pydantic models
            return context.dict()
        elif hasattr(context, "__dict__"):
            return str(context)
        else:
            return str(context)


class ContextLogger(logging.LoggerAdapter):
    """
    Logger adapter that automatically includes context data.
    
    Usage:
        logger = get_logger(__name__)
        logger.info("Processing request", context={"user_id": 123})
    """
    
    def process(self, msg: str, kwargs: dict) -> tuple[str, dict]:
        """Process log message and add context."""
        # Extract context from kwargs
        context = kwargs.pop("context", None)
        
        # Merge with any existing extra context
        extra = kwargs.get("extra", {})
        if context:
            extra["context"] = context
        kwargs["extra"] = extra
        
        return msg, kwargs


def setup_logging(
    service: str = "ai-service",
    environment: Optional[str] = None,
    log_level: str = "INFO",
    log_file: Optional[str] = None,
    max_bytes: int = 10 * 1024 * 1024,  # 10MB
    backup_count: int = 5,
) -> None:
    """
    Configure structured JSON logging for the application.
    
    Args:
        service: Service name for log entries
        environment: Environment name (defaults to ENVIRONMENT env var)
        log_level: Minimum log level (DEBUG, INFO, WARNING, ERROR)
        log_file: Path to log file (optional, defaults to stdout only)
        max_bytes: Max size of log file before rotation
        backup_count: Number of backup files to keep
    """
    env = environment or os.getenv("ENVIRONMENT", "production")
    level = getattr(logging, log_level.upper(), logging.INFO)
    
    # Create JSON formatter
    formatter = JsonFormatter(service=service, environment=env)
    
    # Get root logger
    root_logger = logging.getLogger()
    root_logger.setLevel(level)
    
    # Clear existing handlers
    root_logger.handlers.clear()
    
    # Always add stdout handler (for Docker logs)
    stdout_handler = logging.StreamHandler(sys.stdout)
    stdout_handler.setFormatter(formatter)
    stdout_handler.setLevel(level)
    root_logger.addHandler(stdout_handler)
    
    # Add file handler if specified
    if log_file:
        log_path = Path(log_file)
        log_path.parent.mkdir(parents=True, exist_ok=True)
        
        file_handler = RotatingFileHandler(
            log_file,
            maxBytes=max_bytes,
            backupCount=backup_count,
            encoding="utf-8",
        )
        file_handler.setFormatter(formatter)
        file_handler.setLevel(level)
        root_logger.addHandler(file_handler)
    
    # Reduce noise from third-party libraries
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)
    logging.getLogger("urllib3").setLevel(logging.WARNING)
    logging.getLogger("asyncio").setLevel(logging.WARNING)
    logging.getLogger("uvicorn.access").setLevel(logging.WARNING)


def get_logger(name: str) -> ContextLogger:
    """
    Get a context-aware logger instance.
    
    Args:
        name: Logger name (typically __name__)
        
    Returns:
        ContextLogger instance with structured logging support
        
    Usage:
        logger = get_logger(__name__)
        logger.info("User logged in", context={"user_id": 123, "ip": "1.2.3.4"})
    """
    return ContextLogger(logging.getLogger(name), {})


# Convenience function for logging with context
def log_with_context(
    logger: logging.Logger,
    level: int,
    message: str,
    context: Optional[dict] = None,
    **kwargs: Any,
) -> None:
    """
    Log a message with additional context.
    
    Args:
        logger: Logger instance
        level: Log level (logging.INFO, etc.)
        message: Log message
        context: Additional context data
        **kwargs: Additional logging kwargs
    """
    extra = kwargs.pop("extra", {})
    if context:
        extra["context"] = context
    logger.log(level, message, extra=extra, **kwargs)
