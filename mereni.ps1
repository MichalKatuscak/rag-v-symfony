# Spuštění všech měření na Windows s GPU NVIDIA. Ollama běží nativně (kvůli GPU), zbytek v Dockeru.
# Použití (PowerShell v kořeni repa):  .\mereni.ps1
# Volitelně:  $env:MODELY = "maly"   (jen gemma4:e2b)
$ErrorActionPreference = "Stop"

Write-Host "== Kontrola Ollamy"
$ver = Invoke-RestMethod http://localhost:11434/api/version
Write-Host "Ollama $($ver.version)"
foreach ($m in @("bge-m3", "gemma4:e2b", "gemma4:12b")) { ollama pull $m }

$gpu = (nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader) -join "; "
Write-Host "GPU: $gpu"
$head = (git rev-parse --short HEAD) 2>$null

# Reranker na GPU (compose.gpu.yaml); na CPU by měření hledání trvalo hodiny.
$env:COMPOSE_FILE = "compose.yaml;compose.gpu.yaml"

Write-Host "== Databáze a reranker"
docker compose up -d --build db reranker
Write-Host "Čekám na reranker (první start stahuje model)..."
for ($i = 0; $i -lt 120; $i++) {
    try { docker compose exec -T reranker curl -sf http://localhost:8080/health | Out-Null; if ($LASTEXITCODE -eq 0) { break } } catch {}
    Start-Sleep -Seconds 5
}

Write-Host "== Měření hledání (E1–E3, s rerankerem)"
docker compose run --rm --build -e GPU_POPIS="$gpu" -e GIT_HEAD="$head" -e MODELY="$env:MODELY" app bash bin/mereni hledani
if ($LASTEXITCODE -ne 0) { throw "Měření hledání selhalo" }

Write-Host "== Zastavuji reranker, ať jazykové modely mají celou paměť GPU"
docker compose stop reranker

Write-Host "== Ostatní měření (E4–E8)"
docker compose run --rm -e GPU_POPIS="$gpu" -e GIT_HEAD="$head" -e MODELY="$env:MODELY" app bash bin/mereni ostatni
if ($LASTEXITCODE -ne 0) { throw "Měření selhalo" }
Write-Host "Hotovo. Výsledky jsou ve složce vysledky\"
