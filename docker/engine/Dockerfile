FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1
ENV PIP_NO_CACHE_DIR=1

WORKDIR /app/engine

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates libgomp1 && rm -rf /var/lib/apt/lists/*

COPY engine/pyproject.toml ./pyproject.toml
COPY engine/CONTRACT_VERSION ./CONTRACT_VERSION
COPY engine/app ./app
COPY engine/tests ./tests

RUN pip install --upgrade pip && pip install ".[dev]"

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
