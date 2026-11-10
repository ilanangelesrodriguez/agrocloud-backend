"""Descarga segura desde la cuenta Cloudinary confiable: evita SSRF y redirecciones."""
from urllib.parse import urlparse
import httpx
from fastapi import HTTPException
from agrocloud_ai.core.settings import Settings

async def download_cloudinary_image(url: str, settings: Settings) -> bytes:
    parsed = urlparse(url)
    if (parsed.scheme != "https" or parsed.hostname != "res.cloudinary.com" or parsed.port not in (None, 443)
        or parsed.username or parsed.password or parsed.query or parsed.fragment):
        raise HTTPException(422, "URL de imagen no admitida")
    if not settings.cloudinary_cloud_name:
        raise HTTPException(503, "CLOUDINARY_CLOUD_NAME no configurado")
    prefix = f"/{settings.cloudinary_cloud_name}/image/upload/"
    if not parsed.path.startswith(prefix) or "%" in parsed.path or ".." in parsed.path:
        raise HTTPException(422, "URL fuera de la cuenta Cloudinary autorizada")
    try:
        async with httpx.AsyncClient(follow_redirects=False, timeout=20) as client:
            async with client.stream("GET", url) as response:
                if response.status_code != 200:
                    raise HTTPException(422, "Imagen Cloudinary inaccesible")
                mime = response.headers.get("content-type", "").split(";", 1)[0].strip().lower()
                if mime not in {"image/jpeg", "image/png", "image/webp"}:
                    raise HTTPException(415, "Formato de Cloudinary no admitido")
                parts = []
                count = 0
                async for chunk in response.aiter_bytes():
                    count += len(chunk)
                    if count > settings.max_image_bytes:
                        raise HTTPException(413, "Archivo supera límite")
                    parts.append(chunk)
                return b"".join(parts)
    except httpx.HTTPError as exc:
        raise HTTPException(502, "Error de red descargando evidencia") from exc
