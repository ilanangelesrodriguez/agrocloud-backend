"""Adaptador HTTP de Roboflow sin OpenCV ni inference-sdk."""

import base64
import logging
from io import BytesIO

import httpx
from PIL import Image

from agrocloud_ai.application.ports import InferenceProvider
from agrocloud_ai.core.settings import Settings
from agrocloud_ai.core.exceptions import InferenceProviderError

logger = logging.getLogger(__name__)


class RoboflowAdapter(InferenceProvider):
    def __init__(self, settings: Settings):
        self.settings = settings

    async def predict(self, image: Image.Image) -> object:
        if not self.settings.roboflow_api_key:
            raise InferenceProviderError(
                "ROBOFLOW_API_KEY no configurada"
            )

        if self.settings.roboflow_mode != "model":
            raise InferenceProviderError(
                "Este adaptador admite ROBOFLOW_MODE=model"
            )

        buffer = BytesIO()
        image.save(buffer, format="JPEG", quality=90)

        encoded = base64.b64encode(
            buffer.getvalue()
        ).decode("ascii")

        url = (
            f"{self.settings.roboflow_api_url.rstrip('/')}/"
            f"{self.settings.roboflow_model_id}"
        )

        try:
            timeout = httpx.Timeout(
                self.settings.roboflow_timeout_seconds,
                connect=10.0,
            )

            async with httpx.AsyncClient(timeout=timeout) as client:
                response = await client.post(
                    url,
                    params={
                        "api_key": self.settings.roboflow_api_key,
                        "confidence": int(
                            self.settings.min_confidence * 100
                        ),
                    },
                    headers={
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    content=encoded,
                )

            response.raise_for_status()
            return response.json()

        except httpx.HTTPStatusError as exc:
            logger.error(
                "Roboflow HTTP status=%s, modelo=%s",
                exc.response.status_code,
                self.settings.roboflow_model_id,
            )
            raise InferenceProviderError(
                "Error HTTP del proveedor Roboflow"
            ) from None

        except (httpx.RequestError, ValueError, OSError) as exc:
            logger.error(
                "Roboflow error tipo=%s",
                type(exc).__name__,
            )
            raise InferenceProviderError(
                "No se pudo completar la inferencia"
            ) from None
