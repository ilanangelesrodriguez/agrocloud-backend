"""Configuración centralizada y validada; ninguna credencial en código fuente."""
from functools import lru_cache
from typing import Literal

from pydantic import Field, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")
    app_env: Literal["development", "test", "production"] = "development"
    service_api_key: str = ""
    roboflow_api_key: str = ""
    roboflow_workspace: str = "ilan-angeles"
    roboflow_model_id: str = "ilan-angeles/agrocloud-plagas-de-palta-2-rfdetr-small-t1"
    roboflow_trained_model_id: str = "ilan-angeles/agrocloud-plagas-de-palta-2-rfdetr-small-t1"
    roboflow_mode: Literal["model", "workflow"] = "model"
    roboflow_workflow_id: str = "agrocloud-plagas-de-palta"
    roboflow_api_url: str = "https://serverless.roboflow.com"
    roboflow_timeout_seconds: int = Field(90, ge=10, le=300)
    min_confidence: float = Field(0.20, ge=0, le=1)
    high_confidence: float = Field(0.75, ge=0, le=1)
    max_image_mb: int = Field(3, ge=1, le=20)
    max_image_pixels: int = Field(16_000_000, ge=100_000, le=100_000_000)
    max_batch_size: int = Field(5, ge=1, le=20)
    max_concurrent_inferences: int = Field(3, ge=1, le=20)
    database_url: str = "sqlite:///./data/agrocloud_ai.sqlite3"
    auto_create_tables: bool = True
    cloudinary_url: str = ""
    cloudinary_cloud_name: str = ""
    cloudinary_folder: str = "agrocloud/ai/rfdetr-v2"

    @property
    def max_image_bytes(self) -> int:
        return self.max_image_mb * 1024 * 1024

    @model_validator(mode="after")
    def validate_configuration(self):
        if self.high_confidence < self.min_confidence:
            raise ValueError("HIGH_CONFIDENCE debe ser >= MIN_CONFIDENCE")
        if self.app_env == "production":
            if len(self.service_api_key) < 24 or not self.roboflow_api_key:
                raise ValueError("Producción requiere SERVICE_API_KEY robusta y ROBOFLOW_API_KEY")
            if self.database_url.startswith("sqlite"):
                raise ValueError("Producción requiere PostgreSQL en DATABASE_URL")
            if self.auto_create_tables:
                raise ValueError("En producción AUTO_CREATE_TABLES=false y ejecutar migraciones Alembic")
        if not self.cloudinary_url or not self.cloudinary_cloud_name:
            raise ValueError(
                "Cloudinary es obligatorio: configura CLOUDINARY_URL "
                "y CLOUDINARY_CLOUD_NAME"
            )
        return self

@lru_cache
def get_settings() -> Settings:
    return Settings()
