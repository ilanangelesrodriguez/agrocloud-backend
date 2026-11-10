"""Punto de entrada FastAPI; Swagger siempre disponible en /docs."""
import asyncio
from contextlib import asynccontextmanager
from fastapi import Depends,FastAPI
from fastapi.responses import RedirectResponse
from agrocloud_ai import __version__
from agrocloud_ai.api.routes import health, models, analyses, inferences, statistics
from agrocloud_ai.core.security import require_service_key
from agrocloud_ai.core.settings import get_settings
from agrocloud_ai.infrastructure.persistence.database import initialize_database

@asynccontextmanager
async def lifespan(app:FastAPI):
    settings=get_settings()
    initialize_database(settings)
    app.state.inference_gate=asyncio.Semaphore(settings.max_concurrent_inferences)
    yield

app=FastAPI(title="AgroCloud AI · RF-DETR Small V2",version=__version__,
    description=("API Python independiente de visión por computadora basada en RF-DETR Small V2. "
                 "Recibe SOLO imágenes y devuelve resultados técnicos; no gestiona empresas, "
                 "lotes, inspecciones, validaciones ni dictámenes. "
                 "Las métricas de evaluación Test y las métricas operativas son diferentes. "
                 "Endpoints de datos protegidos mediante X-API-Key; usa Authorize en Swagger."),
    docs_url="/docs",redoc_url="/redoc",openapi_url="/openapi.json",lifespan=lifespan)

app.include_router(health.router)
for route in (models.router, analyses.router, inferences.router, statistics.router):
    app.include_router(route,prefix="/api/v1",dependencies=[Depends(require_service_key)])

@app.get("/",include_in_schema=False)
def home():
    return RedirectResponse(url="/docs")
