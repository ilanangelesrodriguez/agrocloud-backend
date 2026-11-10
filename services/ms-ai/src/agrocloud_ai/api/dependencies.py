"""Adaptadores externos se inyectan; pruebas usan dobles sin llamadas Roboflow."""
import asyncio
from fastapi import Request, Depends
from agrocloud_ai.application.ports import InferenceProvider, ImageStorage
from agrocloud_ai.core.settings import get_settings, Settings
from agrocloud_ai.infrastructure.inference.roboflow import RoboflowAdapter
from agrocloud_ai.infrastructure.storage.annotated import AnnotatedStorage

def get_provider(settings: Settings = Depends(get_settings)) -> InferenceProvider:
    return RoboflowAdapter(settings)

def get_storage(settings: Settings = Depends(get_settings)) -> ImageStorage:
    return AnnotatedStorage(settings)

def get_gate(request: Request) -> asyncio.Semaphore:
    return request.app.state.inference_gate
