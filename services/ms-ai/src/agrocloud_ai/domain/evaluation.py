"""Snapshots científicos auditables. Nunca confundir con métricas de producción."""
from functools import lru_cache
from importlib.resources import files
import json
from agrocloud_ai.domain.schemas import ScientificEvaluation

@lru_cache
def load_evaluation() -> ScientificEvaluation:
    raw = files("agrocloud_ai").joinpath("resources/evaluation_v2.json").read_text(encoding="utf-8")
    return ScientificEvaluation.model_validate_json(raw)

@lru_cache
def load_confusion_matrix() -> dict:
    return json.loads(files("agrocloud_ai").joinpath("resources/confusion_matrix_test_v2.json").read_text(encoding="utf-8"))
