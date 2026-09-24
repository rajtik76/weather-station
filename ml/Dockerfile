# The forecast service. Built from ml/ as the context; the model is not in
# the image but mounted at /models, so a retrain needs no rebuild.

# Dependencies into a virtualenv, with uv. Training-only packages stay out.
FROM ghcr.io/astral-sh/uv:python3.14-trixie-slim AS venv

ENV UV_COMPILE_BYTECODE=1 UV_LINK_MODE=copy UV_PYTHON_DOWNLOADS=never

WORKDIR /app

COPY pyproject.toml uv.lock .python-version ./
RUN uv sync --frozen --no-default-groups --no-install-project \
    # The packages' own test suites are a third of the venv and never run here.
    && find .venv -type d -name tests -prune -exec rm -rf {} +

# Runtime: the same Python base without uv, plus the virtualenv and the code.
FROM python:3.14-slim-trixie AS run

WORKDIR /app

COPY --from=venv /app/.venv ./.venv
COPY features.py forecast.py correction.py serve.py ./

ENV PATH="/app/.venv/bin:$PATH" \
    PYTHONUNBUFFERED=1 \
    MODEL_PATH=/models/forecast.joblib \
    PORT=8000

USER nobody

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4)"]

CMD ["python", "serve.py"]
