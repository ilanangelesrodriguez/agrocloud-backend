"""Alembic lee DATABASE_URL del entorno sin registrar credenciales."""
from logging.config import fileConfig
from alembic import context
from sqlalchemy import engine_from_config, pool
from agrocloud_ai.core.settings import get_settings
from agrocloud_ai.infrastructure.persistence.models import Base

config=context.config
# Sin fileConfig: este proyecto no necesita sección de logging en alembic.ini.
config.set_main_option("sqlalchemy.url", get_settings().database_url.replace("%", "%%"))
target_metadata=Base.metadata

def run_migrations_offline():
    context.configure(url=get_settings().database_url,target_metadata=target_metadata,literal_binds=True)
    with context.begin_transaction():
        context.run_migrations()

def run_migrations_online():
    engine=engine_from_config(config.get_section(config.config_ini_section),prefix="sqlalchemy.",poolclass=pool.NullPool)
    with engine.connect() as connection:
        context.configure(connection=connection,target_metadata=target_metadata)
        with context.begin_transaction():
            context.run_migrations()
    engine.dispose()

if context.is_offline_mode(): run_migrations_offline()
else: run_migrations_online()
