from fastapi import APIRouter, Depends
from agrocloud_ai.core.settings import Settings, get_settings
from agrocloud_ai.domain.evaluation import load_confusion_matrix, load_evaluation
from agrocloud_ai.domain.schemas import ScientificEvaluation

router=APIRouter(prefix="/model", tags=["Modelo y evaluación científica"])

@router.get("", summary="Ficha y versión del modelo desplegado")
def model_card(settings:Settings=Depends(get_settings)):
    report=load_evaluation()
    return {"family":"RF-DETR Small", "task":"object-detection",
            "inference_model_id":settings.roboflow_model_id,
            "training_model_id":settings.roboflow_trained_model_id,
            "dataset_version":2, "provider":"Roboflow", "mode":settings.roboflow_mode,
            "threshold":settings.min_confidence,
            "classes":list(report.dataset["annotations_by_class"]),
            "evaluation_id":report.metadata["evaluation_id"],
            "requires_human_review":True}

@router.get("/evaluation", response_model=ScientificEvaluation,
            summary="Evaluación oficial congelada, no métricas productivas")
def evaluation():
    return load_evaluation()

@router.get("/classes", summary="Rendimiento por clase en Test")
def classes():
    report=load_evaluation()
    return {"split":"test", "evaluation_id":report.metadata["evaluation_id"],
            "classes":report.per_class_test,
            "warning":"Sin instancias Test la métrica es null, no cero."}

@router.get("/confusion-matrix",summary="Matriz de confusión de Test a umbral 0.40")
def confusion_matrix():
    return load_confusion_matrix()
