
"""Almacenamiento de imágenes anotadas exclusivamente en Cloudinary."""

import asyncio
from io import BytesIO
from urllib.parse import unquote, urlsplit

import cloudinary
import cloudinary.uploader

from agrocloud_ai.application.ports import ImageStorage
from agrocloud_ai.core.settings import Settings
from agrocloud_ai.core.exceptions import StorageError


class AnnotatedStorage(ImageStorage):
    def __init__(self, settings: Settings):
        self.settings = settings

    def _save_sync(self, jpg: bytes, request_id: str) -> str:
        parts = urlsplit(self.settings.cloudinary_url)

        if (
            parts.scheme != "cloudinary"
            or not parts.username
            or not parts.password
            or not parts.hostname
            or parts.hostname != self.settings.cloudinary_cloud_name
        ):
            raise StorageError("Configuración Cloudinary inválida")

        cloudinary.config(
            cloud_name=self.settings.cloudinary_cloud_name,
            api_key=unquote(parts.username),
            api_secret=unquote(parts.password),
            secure=True,
        )

        result = cloudinary.uploader.upload(
            BytesIO(jpg),
            folder=self.settings.cloudinary_folder,
            public_id=request_id,
            overwrite=False,
            resource_type="image",
            format="jpg",
        )

        url = result.get("secure_url")
        parsed = urlsplit(url) if isinstance(url, str) else None

        if (
            parsed is None
            or parsed.scheme != "https"
            or parsed.hostname != "res.cloudinary.com"
            or not parsed.path.startswith(
                f"/{self.settings.cloudinary_cloud_name}/image/upload/"
            )
        ):
            raise StorageError(
                "Cloudinary no devolvió una URL válida"
            )

        return url

    async def save(self, jpg: bytes, request_id: str) -> str:
        try:
            return await asyncio.to_thread(
                self._save_sync, jpg, request_id
            )
        except StorageError:
            raise
        except Exception as exc:
            raise StorageError(
                "No se pudo guardar la imagen en Cloudinary"
            ) from exc
