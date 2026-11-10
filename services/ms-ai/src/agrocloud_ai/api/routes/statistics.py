"""Estadísticas agregadas sobre funcionamiento del modelo y del servicio."""
from fastapi import APIRouter, Depends, Query
from sqlalchemy.orm import Session
from agrocloud_ai.application.statistics import overview, class_frequency, error_frequency, daily
from agrocloud_ai.domain.schemas import ServiceOverview
from agrocloud_ai.infrastructure.persistence.database import get_db

router = APIRouter(prefix="/stats", tags=["Estadísticas técnicas"])


@router.get("/overview", response_model=ServiceOverview, summary="Volumen, éxito y tiempos p50/p95")
def get_overview(days: int = Query(30, ge=1, le=365), db: Session = Depends(get_db)):
    return overview(db, days)


@router.get("/classes", summary="Frecuencia de clases detectadas (no precisión)")
def get_classes(days: int = Query(30, ge=1, le=365), db: Session = Depends(get_db)):
    return {"days": days, "classes": class_frequency(db, days),
            "note": "Son predicciones técnicas; no hallazgos confirmados."}


@router.get("/errors", summary="Errores de inferencia agregados")
def get_errors(days: int = Query(30, ge=1, le=365), db: Session = Depends(get_db)):
    return {"days": days, "errors": error_frequency(db, days)}


@router.get("/daily", summary="Ejecuciones por día UTC")
def get_daily(days: int = Query(30, ge=1, le=365), db: Session = Depends(get_db)):
    return {"timezone": "UTC", "days": days, "items": daily(db, days)}
