from io import BytesIO
from PIL import Image
from fastapi import HTTPException
import pytest
from agrocloud_ai.domain.vision import load_image,normalize,summarize


def test_normalized_boxes_scale_and_filter():
    img=Image.new("RGB",(400,240))
    raw={"image":{"width":200,"height":120},"predictions":[
        {"class":"GRILLOS","confidence":0.90,"x":100,"y":60,"width":80,"height":40},
        {"class":"ROEDORES","confidence":0.01,"x":10,"y":10,"width":20,"height":20}]}
    ds=normalize(raw,img,0.20,0.75)
    assert len(ds)==1
    assert ds[0].box.model_dump()=={"x1":120,"y1":80,"x2":280,"y2":160}
    assert ds[0].review_priority=="high"


def test_empty_detections_are_not_claimed_pest_free():
    result=summarize([])
    assert "No implica ausencia" in result.conclusion
    assert result.mean_confidence is None


def test_invalid_image_rejected():
    with pytest.raises(HTTPException) as exc:
        load_image(b"not an image",16_000_000)
    assert exc.value.status_code==422


def test_workflow_shape_supported():
    img=Image.new("RGB",(200,120))
    result=normalize([{"predictions":{"image":{"width":200,"height":120},
        "predictions":[{"class":"OXIDIA","confidence":0.99,"x":50,"y":50,"width":30,"height":30}]}}],img,0.2,0.75)
    assert result[0].class_name=="OXIDIA"
