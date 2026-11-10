"""Persistencia EXCLUSIVA de inferencias de IA (sin IDs ni tablas agrícolas)."""
from datetime import datetime, timezone
from sqlalchemy import DateTime, Float, ForeignKey, Integer, String, Text
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    pass


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class InferenceRun(Base):
    __tablename__ = "ai_inference_runs"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    request_id: Mapped[str] = mapped_column(String(36), unique=True, index=True, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, nullable=False)
    status: Mapped[str] = mapped_column(String(20), index=True, nullable=False)
    model_id: Mapped[str] = mapped_column(String(200), nullable=False)
    trained_model_id: Mapped[str] = mapped_column(String(200), nullable=False)
    image_sha256: Mapped[str] = mapped_column(String(64), nullable=False)
    image_source: Mapped[str] = mapped_column(String(20), nullable=False)
    total_ms: Mapped[int | None] = mapped_column(Integer)
    provider_ms: Mapped[int | None] = mapped_column(Integer)
    image_prep_ms: Mapped[int | None] = mapped_column(Integer)
    output_ms: Mapped[int | None] = mapped_column(Integer)
    detection_count: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    error_type: Mapped[str | None] = mapped_column(String(64))
    result_json: Mapped[str | None] = mapped_column(Text)
    detections: Mapped[list["DetectedObject"]] = relationship(
        back_populates="inference", cascade="all, delete-orphan"
    )


class DetectedObject(Base):
    __tablename__ = "ai_detected_objects"
    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    inference_id: Mapped[int] = mapped_column(ForeignKey("ai_inference_runs.id", ondelete="CASCADE"), index=True)
    detection_id: Mapped[str] = mapped_column(String(36), nullable=False)
    class_name: Mapped[str] = mapped_column(String(100), index=True)
    confidence: Mapped[float] = mapped_column(Float)
    x1: Mapped[int] = mapped_column(Integer)
    y1: Mapped[int] = mapped_column(Integer)
    x2: Mapped[int] = mapped_column(Integer)
    y2: Mapped[int] = mapped_column(Integer)
    inference: Mapped[InferenceRun] = relationship(back_populates="detections")
