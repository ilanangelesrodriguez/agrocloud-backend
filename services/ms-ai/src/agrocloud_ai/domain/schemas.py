"""Contratos de IA independientes de las entidades del sistema consumidor."""
from datetime import datetime
from typing import Any, Literal
from pydantic import BaseModel, ConfigDict, Field, HttpUrl


class ImageFromUrl(BaseModel):
    model_config = ConfigDict(extra="forbid")
    image_url: HttpUrl = Field(description="URL HTTPS bajo la cuenta Cloudinary configurada")


class BBox(BaseModel):
    x1: int
    y1: int
    x2: int
    y2: int


class Detection(BaseModel):
    id: str = Field(description="Identificador de detección dentro de esta inferencia")
    class_name: str
    confidence: float = Field(ge=0, le=1)
    box: BBox
    normalized_box: tuple[float, float, float, float]
    review_priority: Literal["high", "medium", "low"] = Field(
        description="Indicador técnico de incertidumbre, NO grado de riesgo agronómico"
    )


class ImageQuality(BaseModel):
    width: int
    height: int
    mean_brightness: float
    edge_variance: float
    warnings: list[str] = Field(default_factory=list)
    heuristic_only: bool = True


class DetectionSummary(BaseModel):
    total_detections: int
    by_class: dict[str, int]
    mean_confidence: float | None
    max_confidence: float | None
    conclusion: str


class PerformanceTiming(BaseModel):
    total_ms: int
    provider_ms: int
    image_prep_ms: int
    output_ms: int


class AnalysisResult(BaseModel):
    request_id: str = Field(description="UUID técnico generado por este servicio; el consumidor puede almacenarlo")
    status: Literal["completed"] = "completed"
    model_id: str
    trained_model_id: str
    dataset_version: int | None = None
    model_family: str | None = None
    threshold: float
    image_sha256: str
    created_at: datetime
    quality: ImageQuality
    detections: list[Detection]
    summary: DetectionSummary
    timings: PerformanceTiming
    annotated_image_url: str | None = None
    requires_human_review: bool = True


class InferenceItem(BaseModel):
    threshold: float | None = None
    request_id: str
    status: str
    created_at: datetime
    model_id: str
    detection_count: int
    total_ms: int | None = None
    error_type: str | None = None


class InferencePage(BaseModel):
    total: int
    limit: int
    offset: int
    items: list[InferenceItem]


class ServiceOverview(BaseModel):
    processing: int = 0
    total: int
    completed: int
    failed: int
    success_rate_percent: float | None
    mean_total_ms: float | None
    p50_total_ms: float | None
    p95_total_ms: float | None
    mean_provider_ms: float | None
    mean_detections: float | None
    note: str = "Estadísticas técnicas; no representan precisión, recall ni mAP"


class ScientificEvaluation(BaseModel):
    metadata: dict[str, Any]
    dataset: dict[str, Any]
    test_metrics: dict[str, Any]
    validation_metrics: dict[str, Any]
    per_class_test: list[dict[str, Any]]
    per_class_validation: list[dict[str, Any]]
    warnings: list[str]
