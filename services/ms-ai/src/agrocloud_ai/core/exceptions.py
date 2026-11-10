class InferenceProviderError(RuntimeError):
    """Proveedor Roboflow falló o expiró."""

class StorageError(RuntimeError):
    """No pudo almacenarse la imagen anotada."""

class InvalidProviderResponse(ValueError):
    """Payload de inferencia no reconocido."""
