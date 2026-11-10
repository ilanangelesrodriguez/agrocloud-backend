from io import BytesIO
import pytest
from PIL import Image
from fastapi.testclient import TestClient
from agrocloud_ai.api.dependencies import get_provider, get_storage

from agrocloud_ai.main import app
from agrocloud_ai.api.dependencies import get_provider
from agrocloud_ai.core.settings import get_settings

class FakePredictor:
    async def predict(self, image):
        return {"image":{"width":200,"height":120},"predictions":[
            {"class":"GRILLOS","confidence":0.92,"x":100,"y":60,"width":80,"height":40},
            {"class":"ROEDORES","confidence":0.26,"x":50,"y":30,"width":35,"height":30},
            {"class":"OXIDIA","confidence":0.07,"x":10,"y":15,"width":10,"height":10},
        ]}

class FakeStorage:
    async def save(self, jpg: bytes, request_id: str) -> str:
        return (
            "https://res.cloudinary.com/test-cloud/image/upload/"
            f"v1/agrocloud/ai/rfdetr-v2/{request_id}.jpg"
        )

class FaultyPredictor:
    async def predict(self, image):
        from agrocloud_ai.core.exceptions import InferenceProviderError
        raise InferenceProviderError("fake server failure")

@pytest.fixture
def jpg():
    img=Image.new("RGB",(200,120),(100,140,70))
    out=BytesIO();img.save(out,"JPEG")
    return out.getvalue()


@pytest.fixture
def client(monkeypatch, tmp_path):
    monkeypatch.setenv("APP_ENV", "test")
    monkeypatch.setenv("SERVICE_API_KEY", "test-key")
    monkeypatch.setenv("ROBOFLOW_API_KEY", "fake")
    monkeypatch.setenv(
        "DATABASE_URL",
        f"sqlite:///{tmp_path}/test.sqlite3"
    )
    monkeypatch.setenv("AUTO_CREATE_TABLES", "true")

    # Configuración simulada de Cloudinary
    monkeypatch.setenv(
        "CLOUDINARY_URL",
        "cloudinary://fake_key:fake_secret@test-cloud"
    )
    monkeypatch.setenv(
        "CLOUDINARY_CLOUD_NAME",
        "test-cloud"
    )

    get_settings.cache_clear()

    app.dependency_overrides[get_provider] = lambda: FakePredictor()
    app.dependency_overrides[get_storage] = lambda: FakeStorage()

    try:
        with TestClient(app) as c:
            yield c
    finally:
        app.dependency_overrides.clear()
        get_settings.cache_clear()

@pytest.fixture
def auth():
    return {"X-API-Key":"test-key"}
