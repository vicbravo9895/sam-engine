from pydantic import BaseModel
from typing import List, Optional


class RawDetails(BaseModel):
    class Config:
        extra = "forbid"


class Vehicle(BaseModel):
    vehicle_id: Optional[str] = None
    vehicle_name: Optional[str] = None
    vehicle_serial: Optional[str] = None
    vehicle_vin: Optional[str] = None
    vehicle_tags: List[str] = []


class OutputSchema(BaseModel):
    event_id: Optional[str] = None
    event_time_utc: Optional[str] = None
    happened_at_utc: Optional[str] = None
    updated_at_utc: Optional[str] = None
    incident_url: Optional[str] = None
    is_resolved: Optional[bool] = None

    alert_type: Optional[str] = None
    trigger_description: Optional[str] = None
    vehicle: Vehicle = Vehicle()

    severity: Optional[str] = None  # ej. "alta", "media", "baja"
    recommended_actions: List[str] = []
    summary_for_monitoring_team: Optional[str] = None
