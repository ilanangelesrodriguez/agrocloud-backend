"""Scientific snapshots remain bound to the model that was evaluated."""
from fastapi import APIRouter, Depends, HTTPException, Query
from agrocloud_ai.core.settings import Settings, get_settings
from agrocloud_ai.domain.evaluation import load_confusion_matrix, load_evaluation, evaluation_matches
from agrocloud_ai.domain.schemas import ScientificEvaluation
router = APIRouter(prefix="/model", tags=["Modelo y evaluación científica"])


def matches(settings: Settings) -> bool:
    return evaluation_matches(settings.roboflow_model_id, settings.roboflow_trained_model_id, settings.roboflow_mode, settings.roboflow_workspace)


def require_version(version: int) -> None:
    if version != 2:
        raise HTTPException(404, "No hay evaluación verificada para esa versión")


@router.get("")
def model_card(settings: Settings = Depends(get_settings)):
    report = load_evaluation()
    known = matches(settings)
    return {"family": report.metadata["model_architecture"] if known else None, "task": "object-detection",
        "inference_model_id": settings.roboflow_model_id, "training_model_id": settings.roboflow_trained_model_id,
        "dataset_version": 2 if known else None, "provider": "Roboflow", "mode": settings.roboflow_mode,
        "threshold": settings.min_confidence, "high_confidence": settings.high_confidence,
        "classes": list(report.dataset["annotations_by_class"]) if known else [],
        "evaluation_id": report.metadata["evaluation_id"] if known else None, "evaluation_matches_configured": known,
        "requires_human_review": True, "max_image_bytes": settings.max_image_bytes,
        "max_image_pixels": settings.max_image_pixels, "max_batch_size": settings.max_batch_size,
        "max_concurrent_inferences": settings.max_concurrent_inferences, "provider_timeout_seconds": settings.roboflow_timeout_seconds}


@router.get("/versions")
def versions(settings: Settings = Depends(get_settings)):
    report = load_evaluation()
    return {"versions": [{"version": 2, "metadata": report.metadata, "metrics": report.test_metrics,
        "is_configured": matches(settings)}], "configured_model_id": settings.roboflow_model_id}


@router.get("/evaluation", response_model=ScientificEvaluation)
def evaluation(version: int = Query(2, ge=1)):
    require_version(version)
    return load_evaluation()


@router.get("/classes")
def classes(version: int = Query(2, ge=1)):
    require_version(version)
    report = load_evaluation()
    return {"split": "test", "evaluation_id": report.metadata["evaluation_id"], "classes": report.per_class_test,
        "warning": "Sin instancias Test la métrica es null, no cero."}


@router.get("/confusion-matrix")
def confusion_matrix(version: int = Query(2, ge=1)):
    require_version(version)
    return load_confusion_matrix()
