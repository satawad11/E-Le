# E-La Platform - Restore and Verification Script (PowerShell for Windows)
[CmdletBinding()]
param(
    [string]$FilePath = ""
)

$ErrorActionPreference = "Stop"

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  E-La Platform - Restore and Verification" -ForegroundColor Cyan
Write-Host "  Timestamp: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan

$runningContainers = docker ps --format '{{.Names}}'
if ($runningContainers -notcontains "eleave_backend") {
    Write-Host "[ERROR] Container eleave_backend is not running." -ForegroundColor Red
    exit 1
}

if ([string]::IsNullOrWhiteSpace($FilePath)) {
    Write-Host "[VERIFY MODE] Testing restore integrity on temporary database..." -ForegroundColor Yellow
    docker exec eleave_backend php artisan backup:verify-restore
    exit 0
}

if (-not (Test-Path $FilePath)) {
    Write-Host "[ERROR] File not found: $FilePath" -ForegroundColor Red
    exit 1
}

Write-Host "[WARNING] You are about to restore real data from: $FilePath" -ForegroundColor Yellow
Write-Host "This will overwrite existing data in database 'eleave'." -ForegroundColor Red
$confirm = Read-Host "Type 'CONFIRM' to proceed"

if ($confirm -ne "CONFIRM") {
    Write-Host "Restore cancelled by user." -ForegroundColor Yellow
    exit 0
}

Write-Host "Importing database dump into MySQL..." -ForegroundColor Yellow
Get-Content $FilePath | docker exec -i eleave_db mysql -uroot -proot eleave

Write-Host "Clearing application cache..." -ForegroundColor Yellow
docker exec eleave_backend php artisan optimize:clear

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  Restore completed successfully." -ForegroundColor Green
Write-Host "========================================================" -ForegroundColor Cyan
