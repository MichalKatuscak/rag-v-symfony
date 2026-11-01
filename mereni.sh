#!/usr/bin/env bash
# Spuštění všech měření na Linuxu/macOS. Ollama běží nativně na hostu (kvůli GPU), zbytek v Dockeru.
set -euo pipefail
cd "$(dirname "$0")"
curl -sf http://localhost:11434/api/version
for m in bge-m3 gemma4:e2b gemma4:12b; do ollama pull "$m"; done
GPU="$(nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader 2>/dev/null | paste -sd';' || echo 'bez NVIDIA GPU')"
# S NVIDIA GPU poběží reranker na GPU (na CPU trvá měření hledání hodiny).
if nvidia-smi >/dev/null 2>&1; then export COMPOSE_FILE=compose.yaml:compose.gpu.yaml; fi
docker compose up -d --build db reranker
until docker compose exec -T reranker curl -sf http://localhost:8080/health >/dev/null; do sleep 5; done
HEAD_="$(git rev-parse --short HEAD 2>/dev/null || true)"
docker compose run --rm --build -e GPU_POPIS="$GPU" -e GIT_HEAD="$HEAD_" -e MODELY="${MODELY:-maly velky}" app bin/mereni hledani
docker compose stop reranker   # jazykové modely pak mají celou paměť GPU
docker compose run --rm -e GPU_POPIS="$GPU" -e GIT_HEAD="$HEAD_" -e MODELY="${MODELY:-maly velky}" app bin/mereni ostatni
