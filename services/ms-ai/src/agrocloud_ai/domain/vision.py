"""Transformaciones de imagen, cajas y resumen; sin E/S de red o BD."""
from collections import Counter
from io import BytesIO
import math
import re

from fastapi import HTTPException
from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps, ImageStat, UnidentifiedImageError

from agrocloud_ai.core.exceptions import InvalidProviderResponse
from agrocloud_ai.domain.schemas import BBox, Detection, DetectionSummary, ImageQuality

SUPPORTED_FORMATS = {"JPEG", "PNG", "WEBP"}

def load_image(raw: bytes, max_pixels: int) -> Image.Image:
    if not raw:
        raise HTTPException(422, "Imagen vacía")
    try:
        with Image.open(BytesIO(raw)) as src:
            if src.format not in SUPPORTED_FORMATS:
                raise HTTPException(415, "Solo JPG, PNG o WebP")
            if src.width * src.height > max_pixels:
                raise HTTPException(413, "Imagen excede el límite de píxeles")
            oriented = ImageOps.exif_transpose(src)
            oriented.load()
            return oriented.convert("RGB")
    except (OSError, UnidentifiedImageError, Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
        raise HTTPException(422, "Imagen dañada o inválida") from exc


def quality_report(image: Image.Image) -> ImageQuality:
    reduced = image.copy()
    reduced.thumbnail((640, 640))
    grayscale = reduced.convert("L")
    brightness = ImageStat.Stat(grayscale).mean[0]
    edges = ImageStat.Stat(grayscale.filter(ImageFilter.FIND_EDGES)).var[0]
    warnings: list[str] = []
    if min(image.size) < 320:
        warnings.append("Resolución reducida")
    if brightness < 45:
        warnings.append("Posible subexposición")
    if brightness > 220:
        warnings.append("Posible sobreexposición")
    if edges < 30:
        warnings.append("Posible desenfoque (heurística, no veredicto)")
    return ImageQuality(width=image.width, height=image.height,
                        mean_brightness=round(brightness, 2), edge_variance=round(edges, 2),
                        warnings=warnings)


def _predictions_payload(raw: object) -> dict:
    """Admite SDK infer() y run_workflow() con retorno en lista/dict."""
    if isinstance(raw, list):
        if not raw or not isinstance(raw[0], dict):
            raise InvalidProviderResponse("Respuesta de workflow vacía")
        raw = raw[0]
    if not isinstance(raw, dict):
        raise InvalidProviderResponse("Payload inválido")
    nested = raw.get("predictions")
    if isinstance(nested, dict):
        return nested
    if isinstance(nested, list):
        return raw
    raise InvalidProviderResponse("Respuesta sin predicciones")


def normalize(raw: object, image: Image.Image, threshold: float, high_threshold: float) -> list[Detection]:
    payload = _predictions_payload(raw)
    predictions = payload.get("predictions")
    if not isinstance(predictions, list):
        raise InvalidProviderResponse("Predicciones incompatibles")
    dimensions = payload.get("image") or {}
    if not isinstance(dimensions, dict):
        dimensions = {}
    try:
        sw, sh = int(dimensions.get("width", image.width)), int(dimensions.get("height", image.height))
    except (TypeError, ValueError) as exc:
        raise InvalidProviderResponse("Metadatos de dimensiones inválidos") from exc
    if sw <= 0 or sh <= 0:
        raise InvalidProviderResponse("Dimensiones inválidas")
    sx, sy = image.width / sw, image.height / sh
    results: list[Detection] = []
    for idx, p in enumerate(predictions):
        if not isinstance(p, dict):
            continue
        try:
            confidence = float(p["confidence"])
            x, y, w, h = [float(p[k]) for k in ("x", "y", "width", "height")]
        except (KeyError, ValueError, TypeError):
            continue
        if not all(math.isfinite(v) for v in (confidence, x, y, w, h)):
            continue
        if confidence < threshold or confidence > 1 or w <= 0 or h <= 0:
            continue
        x1 = max(0, min(image.width, round((x - w / 2) * sx)))
        y1 = max(0, min(image.height, round((y - h / 2) * sy)))
        x2 = max(0, min(image.width, round((x + w / 2) * sx)))
        y2 = max(0, min(image.height, round((y + h / 2) * sy)))
        if x2 <= x1 or y2 <= y1:
            continue
        cls = re.sub(r"[^A-Z0-9_]", "", str(p.get("class") or p.get("class_name") or "UNKNOWN").upper().replace(" ", "_"))[:100] or "UNKNOWN"
        priority = "high" if confidence >= high_threshold else "medium" if confidence >= max(threshold, 0.40) else "low"
        results.append(Detection(id=f"det-{idx}", class_name=cls, confidence=round(confidence, 6),
                   box=BBox(x1=x1, y1=y1, x2=x2, y2=y2),
                   normalized_box=(round(x1/image.width,6), round(y1/image.height,6),
                                   round(x2/image.width,6), round(y2/image.height,6)),
                   review_priority=priority))
    return results


def summarize(detections: list[Detection]) -> DetectionSummary:
    cs = [d.confidence for d in detections]
    return DetectionSummary(
        total_detections=len(detections),
        by_class=dict(Counter(d.class_name for d in detections)),
        mean_confidence=round(sum(cs)/len(cs), 6) if cs else None,
        max_confidence=max(cs) if cs else None,
        conclusion=("Objetos candidatos detectados; validar manualmente" if cs else
                    "No hay predicciones sobre el umbral. No implica ausencia de plagas"),
    )


def annotate(image: Image.Image, detections: list[Detection]) -> bytes:
    canvas = image.copy()
    draw = ImageDraw.Draw(canvas)
    font = ImageFont.load_default()
    palette = {"high": "#15803d", "medium": "#ca8a04", "low": "#dc2626"}
    for det in detections:
        b = det.box
        draw.rectangle((b.x1, b.y1, b.x2, b.y2), outline=palette[det.review_priority], width=max(2, image.width//400))
        label = f"{det.class_name} {det.confidence*100:.1f}%"
        box = draw.textbbox((0, 0), label, font=font)
        width, height = box[2] + 10, box[3] + 8
        ty = max(0, b.y1-height)
        draw.rectangle((b.x1, ty, min(image.width, b.x1+width), min(image.height, ty+height)), fill="#0f172a")
        draw.text((b.x1+4, ty+3), label, font=font, fill="white")
    out = BytesIO()
    canvas.save(out, format="JPEG", quality=88, optimize=True)
    return out.getvalue()
