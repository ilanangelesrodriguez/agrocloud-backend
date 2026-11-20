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


def evaluation_matches(model_id: str, trained_model_id: str, mode: str = "model", workspace: str | None = None) -> bool:
    metadata = load_evaluation().metadata
    expected_workspace = metadata["project"].split("/", 1)[0]
    expected = {metadata["model_id"], expected_workspace + "/" + metadata["model_id"]}
    return mode == "model" and workspace in (None, expected_workspace) and all(value in expected for value in (model_id, trained_model_id))
