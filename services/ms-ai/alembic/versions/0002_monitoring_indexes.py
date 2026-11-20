"""Queryable threshold and bounded monitoring queries. Not applied automatically."""
from alembic import op
import sqlalchemy as sa
revision = "0002"
down_revision = "0001"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("ai_inference_runs", sa.Column("threshold", sa.Float(), nullable=True))
    bind = op.get_bind()
    if bind.dialect.name == "postgresql":
        op.execute("UPDATE ai_inference_runs SET threshold = (result_json::jsonb ->> 'threshold')::double precision WHERE result_json IS NOT NULL")
    else:
        op.execute("UPDATE ai_inference_runs SET threshold = json_extract(result_json, '$.threshold') WHERE result_json IS NOT NULL")
    op.create_index("ai_run_created_idx", "ai_inference_runs", ["created_at"])
    op.create_index("ai_run_model_date_idx", "ai_inference_runs", ["model_id", "created_at"])
    op.create_index("ai_run_status_date_idx", "ai_inference_runs", ["status", "created_at"])


def downgrade() -> None:
    op.drop_index("ai_run_status_date_idx", table_name="ai_inference_runs")
    op.drop_index("ai_run_model_date_idx", table_name="ai_inference_runs")
    op.drop_index("ai_run_created_idx", table_name="ai_inference_runs")
    op.drop_column("ai_inference_runs", "threshold")
