"""SQL aggregates over technical executions; never tenant business metrics."""
from datetime import datetime, timedelta, timezone
from math import ceil
from sqlalchemy import case, func, select
from sqlalchemy.orm import Session
from agrocloud_ai.domain.schemas import ServiceOverview
from agrocloud_ai.infrastructure.persistence.models import InferenceRun, DetectedObject


def _where(days: int, model_id: str | None = None):
    clauses = [InferenceRun.created_at >= datetime.now(timezone.utc) - timedelta(days=days)]
    if model_id:
        clauses.append(InferenceRun.model_id == model_id)
    return clauses


def overview(db: Session, days: int, model_id: str | None = None) -> ServiceOverview:
    completed = InferenceRun.status == "completed"
    conditions = _where(days, model_id)
    postgres = db.get_bind().dialect.name == "postgresql"
    fields = [
        func.count(InferenceRun.id), func.count().filter(completed),
        func.count().filter(InferenceRun.status == "failed"),
        func.count().filter(InferenceRun.status == "processing"),
        func.avg(InferenceRun.total_ms).filter(completed),
        func.avg(InferenceRun.provider_ms).filter(completed),
        func.avg(InferenceRun.detection_count).filter(completed),
        func.count(InferenceRun.total_ms).filter(completed),
    ]
    if postgres:
        fields.extend([
            func.percentile_cont(0.5).within_group(case((completed, InferenceRun.total_ms), else_=None)),
            func.percentile_disc(0.95).within_group(case((completed, InferenceRun.total_ms), else_=None)),
        ])
    row = db.execute(select(*fields).where(*conditions)).one()
    total, successes, failed, processing, avg_total, avg_provider, avg_detections, count = row[:8]
    p50, p95 = row[8:] if postgres else (None, None)
    if count and not postgres:
        timings = conditions + [completed, InferenceRun.total_ms.is_not(None)]
        ordered = select(InferenceRun.total_ms).where(*timings).order_by(InferenceRun.total_ms)
        middle = db.scalars(ordered.offset((count - 1) // 2).limit(2 if count % 2 == 0 else 1)).all()
        p50 = sum(middle) / len(middle) if middle else None
        p95 = db.scalar(ordered.offset(ceil(count * 0.95) - 1).limit(1))
    rounded = lambda value: round(float(value), 2) if value is not None else None
    return ServiceOverview(total=total, completed=successes, failed=failed, processing=processing,
        success_rate_percent=round(100 * successes / total, 2) if total else None,
        mean_total_ms=rounded(avg_total), p50_total_ms=rounded(p50), p95_total_ms=rounded(p95),
        mean_provider_ms=rounded(avg_provider), mean_detections=rounded(avg_detections))


def class_frequency(db: Session, days: int, model_id: str | None = None):
    q = select(DetectedObject.class_name, func.count(DetectedObject.id), func.avg(DetectedObject.confidence)).join(
        InferenceRun, InferenceRun.id == DetectedObject.inference_id).where(*_where(days, model_id), InferenceRun.status == "completed").group_by(
        DetectedObject.class_name).order_by(func.count(DetectedObject.id).desc())
    return [{"class_name": name, "count": count, "mean_confidence": round(float(avg), 4)} for name, count, avg in db.execute(q)]


def error_frequency(db: Session, days: int, model_id: str | None = None):
    q = select(InferenceRun.error_type, func.count(InferenceRun.id)).where(*_where(days, model_id), InferenceRun.status == "failed").group_by(InferenceRun.error_type)
    return [{"error_type": error, "count": count} for error, count in db.execute(q)]


def daily(db: Session, days: int, model_id: str | None = None):
    date = func.date(func.timezone("UTC", InferenceRun.created_at)) if db.get_bind().dialect.name == "postgresql" else func.date(InferenceRun.created_at)
    q = select(date, InferenceRun.status, func.count(InferenceRun.id)).where(*_where(days, model_id)).group_by(date, InferenceRun.status).order_by(date)
    items = {}
    for day, status, count in db.execute(q):
        key = str(day)
        bucket = items.setdefault(key, {"day": key, "completed": 0, "failed": 0, "processing": 0})
        if status in ("completed", "failed", "processing"):
            bucket[status] = count
    return list(items.values())
