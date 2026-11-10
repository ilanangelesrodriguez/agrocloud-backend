from agrocloud_ai.api.dependencies import get_provider
from agrocloud_ai.infrastructure.persistence.models import InferenceRun, DetectedObject, Base
from tests.conftest import FaultyPredictor


def test_swagger_openapi_and_health(client):
    assert client.get("/docs").status_code == 200
    spec = client.get("/openapi.json").json()
    assert "/api/v1/analyses/file" in spec["paths"]
    assert spec["components"]["securitySchemes"]
    assert client.get("/health").json()["status"] == "ok"
    assert client.get("/ready").status_code == 200


def test_auth_enforced(client, jpg):
    assert client.post("/api/v1/analyses/file", files={"file": ("palta.jpg", jpg, "image/jpeg")}).status_code == 401
    assert client.get("/api/v1/model/evaluation").status_code == 401


def test_inference_and_history_and_annotations(client, jpg, auth):
    response = client.post("/api/v1/analyses/file", headers=auth,
        files={"file": ("palta.jpg", jpg, "image/jpeg")})
    assert response.status_code == 201, response.text
    result = response.json()
    assert result["model_id"] == "ilan-angeles/agrocloud-plagas-de-palta-2-rfdetr-small-t1"
    assert result["status"] == "completed"
    assert result["summary"]["by_class"] == {"GRILLOS": 1, "ROEDORES": 1}
    assert len(result["detections"]) == 2
    assert "inference_id" not in result
    assert not any(k in result for k in ("company_id", "lot_id", "inspection_id", "evidence_id", "reviewer_id"))
    req = result["request_id"]
    history = client.get("/api/v1/inferences", headers=auth).json()
    assert history["total"] == 1
    assert history["items"][0]["request_id"] == req
    assert client.get(f"/api/v1/inferences/{req}", headers=auth).json()["request_id"] == req

    image_response = client.get(
        f"/api/v1/inferences/{req}/image",
        headers=auth,
        follow_redirects=False,
    )

    assert image_response.status_code == 307
    assert image_response.headers["location"] == result["annotated_image_url"]

    assert client.get(f"/api/v1/inferences/{req}/image").status_code == 401
    overview = client.get("/api/v1/stats/overview", headers=auth).json()
    assert overview["completed"] == 1 and overview["failed"] == 0
    assert len(client.get("/api/v1/stats/classes", headers=auth).json()["classes"]) == 2


def test_no_business_identifiers_in_openapi_or_database(client):
    openapi = str(client.get("/openapi.json").json())
    assert not any(name in openapi for name in ("company_id", "lot_id", "inspection_id", "evidence_id", "reviewer_id"))
    assert set(Base.metadata.tables) == {"ai_inference_runs", "ai_detected_objects"}
    assert not any(name in InferenceRun.__table__.columns for name in ("company_id", "lot_id", "inspection_id", "evidence_id"))
    assert not any("reviews" in path for path in client.get("/openapi.json").json()["paths"])


def test_url_rejects_business_fields(client, auth):
    resp = client.post("/api/v1/analyses/url", headers=auth,
        json={"image_url": "https://res.cloudinary.com/demo/image/upload/v1/test.jpg", "company_id": 77})
    assert resp.status_code == 422


def test_scientific_evaluation_is_snapshot(client, auth):
    response = client.get("/api/v1/model/evaluation", headers=auth)
    assert response.status_code == 200
    data = response.json()
    assert data["test_metrics"]["map50"] == 0.7843615131
    chinche = [x for x in data["per_class_test"] if x["class_name"] == "CHINCHE_DAGBERTUS_MINENSIS"][0]
    assert chinche["ground_truth_instances"] == 0 and chinche["map50"] is None
    assert client.get("/api/v1/model/confusion-matrix", headers=auth).status_code == 200


def test_bad_image_and_invalid_url_are_rejected(client, auth):
    assert client.post("/api/v1/analyses/file", headers=auth,
        files={"file": ("bad.jpg", b"nope", "image/jpeg")}).status_code == 422
    assert client.post("/api/v1/analyses/url", headers=auth,
        json={"image_url": "https://example.com/a.jpg"}).status_code == 422


def test_batch(client, jpg, auth):
    response = client.post("/api/v1/analyses/batch", headers=auth,
         files=[("files", ("one.jpg", jpg, "image/jpeg")),
                ("files", ("two.jpg", jpg, "image/jpeg"))])
    assert response.status_code == 201, response.text
    assert len(response.json()) == 2


def test_provider_failure_logged(client, jpg, auth):
    client.app.dependency_overrides[get_provider] = lambda: FaultyPredictor()
    result = client.post("/api/v1/analyses/file", headers=auth,
        files={"file": ("palta.jpg", jpg, "image/jpeg")})
    assert result.status_code == 502
    stats = client.get("/api/v1/stats/overview", headers=auth).json()
    assert stats["failed"] == 1
    errors = client.get("/api/v1/stats/errors", headers=auth).json()["errors"]
    assert errors[0]["error_type"] == "InferenceProviderError"


def test_old_legacy_routes_are_not_present(client, auth):
    assert client.post("/api/v1/detect", headers=auth).status_code == 404
    assert client.post("/api/v1/reviews", headers=auth).status_code == 404
    assert client.get("/api/v1/model", headers=auth).json()["inference_model_id"] == "ilan-angeles/agrocloud-plagas-de-palta-2-rfdetr-small-t1"
