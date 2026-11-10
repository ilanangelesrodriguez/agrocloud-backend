"""Métricas técnicas del microservicio, agregadas SIN datos del negocio."""
from datetime import datetime, timedelta, timezone
import math
from statistics import mean, median
from sqlalchemy import func, select
from sqlalchemy.orm import Session
from agrocloud_ai.domain.schemas import ServiceOverview
from agrocloud_ai.infrastructure.persistence.models import InferenceRun, DetectedObject


def _since(days: int) -> datetime:
    return datetime.now(timezone.utc) - timedelta(days=days)


def _where(days: int):
    return [InferenceRun.created_at >= _since(days)]


def overview(db: Session, days: int) -> ServiceOverview:
    rows = db.scalars(select(InferenceRun).where(*_where(days))).all()
    successes = [r for r in rows if r.status == "completed"]
    times = sorted(r.total_ms for r in successes if r.total_ms is not None)
    p95 = times[math.ceil(len(times) * 0.95) - 1] if times else None

    def avg(values):
        return round(mean(values), 2) if values else None

    return ServiceOverview(
        total=len(rows), completed=len(successes),
        failed=sum(r.status == "failed" for r in rows),
        success_rate_percent=round(100 * len(successes) / len(rows), 2) if rows else None,
        mean_total_ms=avg(times), p50_total_ms=median(times) if times else None,
        p95_total_ms=p95,
        mean_provider_ms=avg([r.provider_ms for r in successes if r.provider_ms is not None]),
        mean_detections=avg([r.detection_count for r in successes]),
    )


def class_frequency(db: Session, days: int):
    q = (
        select(DetectedObject.class_name, func.count(DetectedObject.id), func.avg(DetectedObject.confidence))
        .join(InferenceRun, InferenceRun.id == DetectedObject.inference_id)
        .where(*_where(days))
        .group_by(DetectedObject.class_name)
        .order_by(func.count(DetectedObject.id).desc())
    )
    return [{"class_name": name, "count": count, "mean_confidence": round(avg, 4)}
            for name, count, avg in db.execute(q)]


def error_frequency(db: Session, days: int):
    q = (select(InferenceRun.error_type, func.count(InferenceRun.id))
         .where(*_where(days), InferenceRun.status == "failed")
         .group_by(InferenceRun.error_type))
    return [{"error_type": error, "count": count} for error, count in db.execute(q)]


def daily(db: Session, days: int):
    rows = db.scalars(select(InferenceRun).where(*_where(days))).all()
    items = {}
    for row in rows:
        key = row.created_at.date().isoformat()  # SQLite no conserva tzinfo, tratar como UTC.
        bucket = items.setdefault(key, {"day": key, "completed": 0, "failed": 0, "processing": 0})
        if row.status in bucket:
            bucket[row.status] += 1
    return sorted(items.values(), key=lambda item: item["day"])
