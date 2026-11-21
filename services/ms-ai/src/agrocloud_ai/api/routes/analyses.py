"""API de análisis: acepta fotografías; NO acepta identificadores de otras aplicaciones."""
import asyncio
import logging
from typing import Annotated
from uuid import UUID, uuid4
from fastapi import APIRouter, Depends, File, HTTPException, Request, UploadFile
from sqlalchemy.orm import Session

from agrocloud_ai.api.dependencies import get_provider, get_storage, get_gate
from agrocloud_ai.application.analyze import analyze_image
from agrocloud_ai.application.ports import InferenceProvider, ImageStorage
from agrocloud_ai.core.settings import Settings, get_settings
from agrocloud_ai.domain.schemas import AnalysisResult, BatchAnalysisItem, ImageFromUrl
from agrocloud_ai.infrastructure.persistence.database import build_session_factory, get_db
from agrocloud_ai.infrastructure.storage.remote_image import download_cloudinary_image

router = APIRouter(prefix="/analyses", tags=["Detección RF-DETR"])
logger = logging.getLogger(__name__)


async def _get_bytes(file: UploadFile, settings: Settings) -> bytes:
    if file.content_type not in ("image/jpeg", "image/png", "image/webp"):
        await file.close()
        raise HTTPException(415, "Solo se admite JPG / PNG / WebP")
    try:
        raw = await file.read(settings.max_image_bytes + 1)
    finally:
        await file.close()
    if len(raw) > settings.max_image_bytes:
        raise HTTPException(413, "Imagen supera límite")
    return raw


@router.post("/file", status_code=201, response_model=AnalysisResult,
             summary="Detectar plagas en una fotografía")
async def analyze_file(
    request: Request,
    file: Annotated[UploadFile, File(description="Fotografía JPG, PNG o WEBP")],
    settings: Settings = Depends(get_settings),
    provider: InferenceProvider = Depends(get_provider),
    storage: ImageStorage = Depends(get_storage),
    db: Session = Depends(get_db),
    gate: asyncio.Semaphore = Depends(get_gate),
):
    try:
        correlation_id = str(UUID(request.headers.get("X-Correlation-ID", "")))
    except ValueError:
        correlation_id = str(uuid4())
    try:
        result = await analyze_image(await _get_bytes(file, settings), settings, provider, storage, db, gate)
        logger.info("Evidence inference completed", extra={"correlation_id": correlation_id, "request_id": result.request_id})
        return result
    except Exception as exc:
        logger.warning("Evidence inference failed", extra={"correlation_id": correlation_id, "error_type": type(exc).__name__})
        raise


@router.post("/url", status_code=201, response_model=AnalysisResult,
             summary="Detectar en fotografía existente de Cloudinary")
async def analyze_url(
    payload: ImageFromUrl,
    settings: Settings = Depends(get_settings),
    provider: InferenceProvider = Depends(get_provider),
    storage: ImageStorage = Depends(get_storage),
    db: Session = Depends(get_db),
    gate: asyncio.Semaphore = Depends(get_gate),
):
    raw = await download_cloudinary_image(str(payload.image_url), settings)
    return await analyze_image(raw, settings, provider, storage, db, gate, source="cloudinary")


@router.post("/batch", status_code=201, response_model=list[AnalysisResult],
             summary="Procesar varias fotografías secuencialmente")
async def analyze_batch(
    files: Annotated[list[UploadFile], File(description="De 1 a MAX_BATCH_SIZE imágenes")],
    settings: Settings = Depends(get_settings),
    provider: InferenceProvider = Depends(get_provider),
    storage: ImageStorage = Depends(get_storage),
    db: Session = Depends(get_db),
    gate: asyncio.Semaphore = Depends(get_gate),
):
    if not 1 <= len(files) <= settings.max_batch_size:
        raise HTTPException(422, f"Se admiten entre 1 y {settings.max_batch_size} imágenes")
    items = [await _get_bytes(file, settings) for file in files]
    from agrocloud_ai.domain.vision import load_image
    for raw in items:
        load_image(raw, settings.max_image_pixels)
    # Mantiene exactamente el orden de los archivos, sin conocer su origen de negocio.
    return [await analyze_image(raw, settings, provider, storage, db, gate, source="batch")
            for raw in items]


@router.post("/batch-results", status_code=201, response_model=list[BatchAnalysisItem],
             summary="Procesar un lote con concurrencia limitada y errores por fotografía")
async def analyze_batch_results(
    files: Annotated[list[UploadFile], File(description="De 1 a MAX_BATCH_SIZE imágenes")],
    settings: Settings = Depends(get_settings),
    provider: InferenceProvider = Depends(get_provider),
    storage: ImageStorage = Depends(get_storage),
    gate: asyncio.Semaphore = Depends(get_gate),
):
    if not 1 <= len(files) <= settings.max_batch_size:
        for file in files:
            await file.close()
        raise HTTPException(422, f"Se admiten entre 1 y {settings.max_batch_size} imágenes")
    factory = build_session_factory(settings.database_url)
    limit = asyncio.Semaphore(settings.max_concurrent_inferences)

    async def process(index: int, file: UploadFile) -> BatchAnalysisItem:
        try:
            async with limit:
                raw = await _get_bytes(file, settings)
                with factory() as db:
                    result = await analyze_image(raw, settings, provider, storage, db, gate, source="batch")
                return BatchAnalysisItem(index=index, status="completed", result=result)
        except HTTPException as exc:
            return BatchAnalysisItem(index=index, status="failed", error_code=f"HTTP_{exc.status_code}",
                                     retryable=exc.status_code >= 500 or exc.status_code in (408, 429))
        except Exception as exc:
            logger.error("Batch image failed", extra={"image_index": index, "error_type": type(exc).__name__})
            return BatchAnalysisItem(index=index, status="failed", error_code="INTERNAL_ERROR", retryable=True)
        finally:
            await file.close()

    return await asyncio.gather(*(process(index, file) for index, file in enumerate(files)))
