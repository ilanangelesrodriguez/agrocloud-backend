from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from sqlalchemy import text, select
from agrocloud_ai.infrastructure.persistence.models import InferenceRun
from agrocloud_ai.core.settings import Settings, get_settings
from agrocloud_ai.infrastructure.persistence.database import build_session_factory
from agrocloud_ai import __version__

router=APIRouter(tags=["Estado"])

@router.get("/health", summary="Liveness (sin inferencia externa)")
def health():
    return {"status":"ok", "service":"agrocloud-ai-rfdetr", "version":__version__, "swagger":"/docs"}


@router.get(
    "/ready",
    summary="Readiness de configuración y base de datos"
)
def ready(settings: Settings = Depends(get_settings)):
    config_ok = bool(
        settings.roboflow_api_key
        and settings.service_api_key
        and settings.cloudinary_url
        and settings.cloudinary_cloud_name
    )

    db_ok = False
    schema_ok = False

    try:
        with build_session_factory(settings.database_url)() as db:
            db.execute(text("SELECT 1"))
            db_ok = True
            db.execute(select(InferenceRun.threshold).limit(0))
            schema_ok = True
    except Exception:
        pass

    ready_now = config_ok and db_ok and schema_ok

    return JSONResponse(
        status_code=200 if ready_now else 503,
        content={
            "ready": ready_now,
            "configuration": config_ok,
            "database": db_ok,
            "schema": schema_ok,
            "live_inference_checked": False
        }
    )
