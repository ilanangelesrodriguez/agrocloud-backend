"""Ciclo de vida de SQLAlchemy. SQLite dev; PostgreSQL en producción."""
from functools import lru_cache
from pathlib import Path
from sqlalchemy import create_engine
from sqlalchemy.orm import Session, sessionmaker
from agrocloud_ai.core.settings import get_settings, Settings
from .models import Base

@lru_cache(maxsize=5)
def build_session_factory(url: str) -> sessionmaker[Session]:
    if url.startswith("sqlite:///"):
        dbfile = url.removeprefix("sqlite:///")
        if dbfile != ":memory:":
            Path(dbfile).parent.mkdir(parents=True, exist_ok=True)
    engine = create_engine(url, pool_pre_ping=True, connect_args={"check_same_thread": False} if url.startswith("sqlite") else {})
    return sessionmaker(bind=engine, expire_on_commit=False)

def initialize_database(settings: Settings) -> None:
    factory = build_session_factory(settings.database_url)
    if settings.auto_create_tables:
        Base.metadata.create_all(factory.kw["bind"])

def get_db():
    factory = build_session_factory(get_settings().database_url)
    with factory() as db:
        yield db
