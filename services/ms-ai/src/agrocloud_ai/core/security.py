"""Autenticación servicio-a-servicio. Nunca exponer SERVICE_API_KEY al navegador."""
from hmac import compare_digest
from fastapi import Depends, HTTPException, status
from fastapi.security import APIKeyHeader
from .settings import Settings, get_settings

_key = APIKeyHeader(name="X-API-Key", auto_error=False, description="Clave privada servidor a servidor")

def require_service_key(value: str | None = Depends(_key), settings: Settings = Depends(get_settings)) -> None:
    if not settings.service_api_key:
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "Servicio sin clave configurada")
    if value is None or not compare_digest(value, settings.service_api_key):
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Credenciales de servicio inválidas")
