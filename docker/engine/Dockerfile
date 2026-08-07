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

# SEC-15 — le solveur n'a AUCUNE raison de tourner root : il ne lit que son code
# et n'écrit rien (stateless, port 8000 > 1024). uid/gid 1000 délibérément :
# c'est l'uid hôte standard en dev (bind-mount ./ → les caches pytest/ruff
# écrits par `make test` restent possédés par l'hôte), et l'uid déjà utilisé
# par pdf-worker en prod. Le chown du WORKDIR couvre l'image immuable de prod
# (en dev, le bind-mount recouvre /app de toute façon).
RUN groupadd --gid 1000 engine && useradd --uid 1000 --gid 1000 --no-create-home engine \
    && chown -R engine:engine /app
USER engine

# Les caches des outils SORTENT du bind-mount : en CI le checkout appartient au
# runner (uid 1001), pas à `engine` (1000) — ruff refusait net (« Permission
# denied » sur .ruff_cache, attrapé au premier run CI de cette PR), et sur un
# poste dev ils cessent de polluer le dépôt. /tmp est writable pour tout uid.
ENV RUFF_CACHE_DIR=/tmp/ruff-cache \
    MYPY_CACHE_DIR=/tmp/mypy-cache

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
