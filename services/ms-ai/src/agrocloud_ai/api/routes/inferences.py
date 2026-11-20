"""Historial técnico y acceso protegido a imágenes anotadas locales."""

from datetime import datetime, timedelta, timezone
from typing import Literal
from uuid import UUID
from urllib.parse import urlsplit

from fastapi import APIRouter, Depends, HTTPException, Query
from fastapi.responses import RedirectResponse
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from agrocloud_ai.core.settings import Settings, get_settings
from agrocloud_ai.domain.schemas import (
    InferencePage,
    InferenceItem,
    AnalysisResult,
)
from agrocloud_ai.infrastructure.persistence.database import get_db
from agrocloud_ai.infrastructure.persistence.models import InferenceRun


router = APIRouter(prefix="/inferences", tags=["Historial técnico"])


@router.get("", response_model=InferencePage, summary="Ejecuciones recientes del microservicio")
def list_runs(offset: int = Query(0, ge=0), limit: int = Query(30, ge=1, le=100),
              days: int = Query(30, ge=1, le=365), model_id: str | None = Query(None, min_length=1, max_length=200),
              status: Literal["processing", "completed", "failed"] | None = Query(None), db: Session = Depends(get_db)):
    filters = [InferenceRun.created_at >= datetime.now(timezone.utc) - timedelta(days=days)]
    if model_id:
        filters.append(InferenceRun.model_id == model_id)
    if status:
        filters.append(InferenceRun.status == status)
    total = db.scalar(select(func.count(InferenceRun.id)).where(*filters)) or 0
    runs = db.scalars(select(InferenceRun).where(*filters).order_by(InferenceRun.id.desc()).offset(offset).limit(limit)).all()
    return InferencePage(total=total, offset=offset, limit=limit,
        items=[InferenceItem.model_validate({field: getattr(run, field) for field in InferenceItem.model_fields})
               for run in runs])


@router.get("/{request_id}", response_model=AnalysisResult, summary="Resultado de una inferencia")
def read_run(request_id: UUID, db: Session = Depends(get_db)):
    run = db.scalar(select(InferenceRun).where(InferenceRun.request_id == str(request_id)))
    if run is None or run.status != "completed" or not run.result_json:
        raise HTTPException(404, "Inferencia no encontrada o no completada")
    return AnalysisResult.model_validate_json(run.result_json)



@router.get("/{request_id}/image", summary="Consultar imagen anotada en Cloudinary", response_class=RedirectResponse,
    responses={
        307: {"description": "Redirección a Cloudinary"},
        404: {"description": "Imagen no disponible"},
    },)
def annotated_image(request_id: UUID, db: Session = Depends(get_db), settings: Settings = Depends(get_settings)):
    run = db.scalar(
        select(InferenceRun).where(
            InferenceRun.request_id == str(request_id)
        )
    )

    if run is None or run.status != "completed" or not run.result_json:
        raise HTTPException(404, "Inferencia no encontrada")

    result = AnalysisResult.model_validate_json(run.result_json)
    url = result.annotated_image_url

    if not url:
        raise HTTPException(404, "Imagen anotada no disponible")

    parsed = urlsplit(url)
    expected_prefix = (
        f"/{settings.cloudinary_cloud_name}/image/upload/"
    )

    if (
        parsed.scheme != "https"
        or parsed.hostname != "res.cloudinary.com"
        or parsed.port not in (None, 443)
        or parsed.username
        or parsed.password
        or parsed.query
        or parsed.fragment
        or not parsed.path.startswith(expected_prefix)
        or "%" in parsed.path
        or ".." in parsed.path
    ):
        raise HTTPException(404, "URL de imagen no válida")

    return RedirectResponse(url=url, status_code=307)
