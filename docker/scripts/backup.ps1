# E-La Platform - Automated Backup Script (PowerShell for Windows)
[CmdletBinding()]
param(
    [int]$RetentionDays = 30
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Resolve-Path "$ScriptDir\..\.."
$BackupDir = "$ProjectRoot\backend\storage\app\backup"
$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  E-La Platform - Automated Backup" -ForegroundColor Cyan
Write-Host "  Timestamp: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan

# Check if eleave_backend container is running
$runningContainers = docker ps --format '{{.Names}}'
if ($runningContainers -notcontains "eleave_backend") {
    Write-Host "[ERROR] Container eleave_backend is not running." -ForegroundColor Red
    Write-Host "Please start the stack with: docker compose up -d" -ForegroundColor Yellow
    exit 1
}

Write-Host "[1/3] Triggering Laravel backup (Database + Documents/Signatures)..." -ForegroundColor Yellow
try {
    docker exec eleave_backend php artisan backup:run --only-to-disk=backup
    Write-Host "  [PASS] Backup completed via Laravel backup" -ForegroundColor Green
} catch {
    Write-Host "  [WARN] Fallback: dumping database directly via mysqldump..." -ForegroundColor Magenta
    if (-not (Test-Path $BackupDir)) {
        New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
    }
    $FallbackSql = "$BackupDir\eleave_db_manual_$Timestamp.sql"
    docker exec eleave_db mysqldump -uroot -proot eleave > $FallbackSql
    Write-Host "  [PASS] Database dumped to $FallbackSql" -ForegroundColor Green
}

Write-Host "[2/3] Listing current backups..." -ForegroundColor Yellow
docker exec eleave_backend php artisan backup:list

Write-Host "[3/3] Pruning backups older than $RetentionDays days..." -ForegroundColor Yellow
if (Test-Path $BackupDir) {
    $cutoffDate = (Get-Date).AddDays(-$RetentionDays)
    $oldFiles = Get-ChildItem -Path $BackupDir -File | Where-Object { $_.LastWriteTime -lt $cutoffDate }
    foreach ($file in $oldFiles) {
        Remove-Item $file.FullName -Force
        Write-Host "  Removed old archive: $($file.Name)" -ForegroundColor DarkGray
    }
    Write-Host "  [PASS] Old backup cleanup finished" -ForegroundColor Green
}

Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  Backup process finished successfully." -ForegroundColor Green
Write-Host "========================================================" -ForegroundColor Cyan
exit 0
