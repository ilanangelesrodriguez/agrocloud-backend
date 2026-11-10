FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PORT=8000
WORKDIR /srv
RUN groupadd -r agro && useradd -r -g agro -d /srv agro
COPY pyproject.toml README.md ./
COPY src ./src
COPY alembic ./alembic
COPY alembic.ini ./alembic.ini
RUN pip install --no-cache-dir .[postgres] && mkdir -p /srv/data && chown -R agro:agro /srv
USER agro
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD python -c 'import urllib.request; urllib.request.urlopen("http://127.0.0.1:8000/health", timeout=3)'
CMD ["sh", "-c", "uvicorn agrocloud_ai.main:app --host 0.0.0.0 --port ${PORT:-8000} --workers 1"]
