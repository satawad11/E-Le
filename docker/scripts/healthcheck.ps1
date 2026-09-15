# E-La Platform - Health Check Script (PowerShell for Windows)

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  E-La Platform - System Health Check" -ForegroundColor Cyan
Write-Host "  Timestamp: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan

$failed = 0
$containers = @("eleave_db", "eleave_backend", "eleave_nginx", "eleave_worker", "eleave_scheduler", "eleave_clamav")

Write-Host "`n[1/4] Checking Docker Containers:" -ForegroundColor Yellow
foreach ($c in $containers) {
    $status = docker inspect --format='{{.State.Status}}' $c 2>$null
    $health = docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $c 2>$null
    
    if ($status -eq "running") {
        if ($health -eq "healthy" -or $health -eq "none") {
            Write-Host "  [PASS] $c (Status: $status, Health: $health)" -ForegroundColor Green
        } else {
            Write-Host "  [WARN] $c (Status: $status, Health: $health)" -ForegroundColor Magenta
        }
    } else {
        Write-Host "  [FAIL] $c (Status: $status)" -ForegroundColor Red
        $failed++
    }
}

Write-Host "`n[2/4] Checking Nginx API Gateway:" -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "http://localhost:8001/api/ping" -UseBasicParsing -TimeoutSec 5
    if ($response.StatusCode -eq 200) {
        Write-Host "  [PASS] /api/ping responded with HTTP 200 OK" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] /api/ping returned status code $($response.StatusCode)" -ForegroundColor Red
        $failed++
    }
} catch {
    Write-Host "  [FAIL] Cannot connect to http://localhost:8001/api/ping" -ForegroundColor Red
    $failed++
}

Write-Host "`n[3/4] Checking Database Connection:" -ForegroundColor Yellow
try {
    docker exec eleave_backend php artisan db:show > $null 2>&1
    Write-Host "  [PASS] Database connection is healthy" -ForegroundColor Green
} catch {
    Write-Host "  [FAIL] Cannot connect to database" -ForegroundColor Red
    $failed++
}

Write-Host "`n[4/4] Checking Scheduler Heartbeat:" -ForegroundColor Yellow
try {
    docker exec eleave_backend php artisan operations:check-heartbeats > $null 2>&1
    Write-Host "  [PASS] Scheduler heartbeat check executed" -ForegroundColor Green
} catch {
    Write-Host "  [WARN] Scheduler heartbeat had warnings" -ForegroundColor Magenta
}

Write-Host "`n========================================================" -ForegroundColor Cyan
if ($failed -eq 0) {
    Write-Host "  Result: All systems operational (100% PASS)" -ForegroundColor Green
} else {
    Write-Host "  Result: Found $failed issue(s). Please inspect failed services." -ForegroundColor Red
}
Write-Host "========================================================" -ForegroundColor Cyan
exit $failed
