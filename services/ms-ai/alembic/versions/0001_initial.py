"""Esquema inicial de historial técnico de inferencias (sin entidades de negocio).

Revision ID: 0001
Revises:
"""
from alembic import op
import sqlalchemy as sa

revision = "0001"
down_revision = None
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "ai_inference_runs",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("request_id", sa.String(36), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("status", sa.String(20), nullable=False),
        sa.Column("model_id", sa.String(200), nullable=False),
        sa.Column("trained_model_id", sa.String(200), nullable=False),
        sa.Column("image_sha256", sa.String(64), nullable=False),
        sa.Column("image_source", sa.String(20), nullable=False),
        sa.Column("total_ms", sa.Integer()),
        sa.Column("provider_ms", sa.Integer()),
        sa.Column("image_prep_ms", sa.Integer()),
        sa.Column("output_ms", sa.Integer()),
        sa.Column("detection_count", sa.Integer(), nullable=False),
        sa.Column("error_type", sa.String(64)),
        sa.Column("result_json", sa.Text()),
    )
    op.create_index("ix_ai_inference_runs_request_id", "ai_inference_runs", ["request_id"], unique=True)
    op.create_index("ix_ai_inference_runs_status", "ai_inference_runs", ["status"])
    op.create_table(
        "ai_detected_objects",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("inference_id", sa.Integer(), sa.ForeignKey("ai_inference_runs.id", ondelete="CASCADE")),
        sa.Column("detection_id", sa.String(36), nullable=False),
        sa.Column("class_name", sa.String(100)),
        sa.Column("confidence", sa.Float()),
        sa.Column("x1", sa.Integer()),
        sa.Column("y1", sa.Integer()),
        sa.Column("x2", sa.Integer()),
        sa.Column("y2", sa.Integer()),
    )
    op.create_index("ix_ai_detected_objects_inference_id", "ai_detected_objects", ["inference_id"])
    op.create_index("ix_ai_detected_objects_class_name", "ai_detected_objects", ["class_name"])


def downgrade() -> None:
    op.drop_table("ai_detected_objects")
    op.drop_table("ai_inference_runs")
