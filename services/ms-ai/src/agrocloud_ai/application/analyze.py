"""Caso de uso: registrar inferencia -> detectar -> visualizar -> persistir."""
import asyncio
from datetime import datetime, timezone
from hashlib import sha256
import time
from uuid import uuid4

from fastapi import HTTPException
from sqlalchemy.orm import Session

from agrocloud_ai.application.ports import InferenceProvider, ImageStorage
from agrocloud_ai.core.exceptions import InvalidProviderResponse, InferenceProviderError, StorageError
from agrocloud_ai.core.settings import Settings
from agrocloud_ai.domain.evaluation import evaluation_matches
from agrocloud_ai.domain.schemas import AnalysisResult, PerformanceTiming
from agrocloud_ai.domain.vision import annotate, load_image, normalize, quality_report, summarize
from agrocloud_ai.infrastructure.persistence.models import DetectedObject, InferenceRun

async def analyze_image(raw: bytes, settings: Settings, predictor: InferenceProvider,
                        storage: ImageStorage, db: Session, gate: asyncio.Semaphore,
                        source: str = "upload") -> AnalysisResult:
    """No promete tiempo real ni diagnósticos. Cada solicitud crea una ejecución inmutable."""
    started = time.perf_counter()
    # Rechazar imágenes inválidas antes de consumir recursos o almacenar ejecuciones.
    if len(raw) > settings.max_image_bytes:
        raise HTTPException(413, "Imagen demasiado grande")
    img = load_image(raw, settings.max_image_pixels)
    img_quality = quality_report(img)
    image_prep_ms = round((time.perf_counter() - started) * 1000)
    request_id = str(uuid4())
    run = InferenceRun(request_id=request_id, threshold=settings.min_confidence, model_id=settings.roboflow_model_id,
                       trained_model_id=settings.roboflow_trained_model_id,
                       image_sha256=sha256(raw).hexdigest(), image_source=source,
                       status="processing")
    db.add(run)
    db.commit()
    db.refresh(run)
    provider_ms = 0
    output_ms = 0
    try:
        async with gate:
            provider_started = time.perf_counter()
            provider_result = await predictor.predict(img)
            provider_ms = round((time.perf_counter() - provider_started) * 1000)
        detections = normalize(provider_result, img, settings.min_confidence, settings.high_confidence)
        output_started = time.perf_counter()
        labeled_bytes = await asyncio.to_thread(annotate, img, detections)
        public_url = await storage.save(labeled_bytes, request_id)
        output_ms = round((time.perf_counter() - output_started) * 1000)
        
        result = AnalysisResult(
            request_id=request_id,
            model_id=settings.roboflow_model_id,
            trained_model_id=settings.roboflow_trained_model_id,
            dataset_version=2 if evaluation_matches(settings.roboflow_model_id, settings.roboflow_trained_model_id, settings.roboflow_mode, settings.roboflow_workspace) else None,
            model_family="RF-DETR Small" if evaluation_matches(settings.roboflow_model_id, settings.roboflow_trained_model_id, settings.roboflow_mode, settings.roboflow_workspace) else None,
            threshold=settings.min_confidence,
            image_sha256=run.image_sha256,
            created_at=datetime.now(timezone.utc),
            quality=img_quality,
            detections=detections,
            summary=summarize(detections),
            timings=PerformanceTiming(
                total_ms=round(
                    (time.perf_counter() - started) * 1000
                ),
                provider_ms=provider_ms,
                image_prep_ms=image_prep_ms,
                output_ms=output_ms,
            ),
            annotated_image_url=public_url,
        )

        run.status = "completed"
        run.total_ms = result.timings.total_ms
        run.provider_ms = provider_ms
        run.image_prep_ms = image_prep_ms
        run.output_ms = output_ms
        run.detection_count = len(detections)
        run.result_json = result.model_dump_json()
        run.detections = [DetectedObject(detection_id=d.id, class_name=d.class_name,
                           confidence=d.confidence, x1=d.box.x1, y1=d.box.y1,
                           x2=d.box.x2, y2=d.box.y2) for d in detections]
        db.commit()
        return result
    except Exception as exc:
        db.rollback()
        existing = db.get(InferenceRun, run.id)
        if existing:
            existing.status = "failed"
            existing.total_ms = round((time.perf_counter() - started)*1000)
            existing.provider_ms = provider_ms
            existing.error_type = type(exc).__name__
            db.commit()
        if isinstance(exc, InferenceProviderError):
            raise HTTPException(502, "No se pudo completar la inferencia; consulta la ejecución") from exc
        if isinstance(exc, StorageError):
            raise HTTPException(502, "No se pudo guardar la imagen anotada") from exc
        if isinstance(exc, InvalidProviderResponse):
            raise HTTPException(502, "Respuesta incompatible del modelo") from exc
        raise
