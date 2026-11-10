"""Puertos para intercambiar proveedores sin alterar la lógica de visión."""
from typing import Protocol
from PIL import Image

class InferenceProvider(Protocol):
    async def predict(self, image: Image.Image) -> object: ...

class ImageStorage(Protocol):
    async def save(self, jpg: bytes, request_id: str) -> str:
        """Retorna la URL HTTPS de Cloudinary."""
        ...