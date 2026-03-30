# ABIC Accounting System - Backend Setup Script for Windows
$ErrorActionPreference = "Stop"

function Write-Header($text) {
    Write-Host "`n======================================" -ForegroundColor Green
    Write-Host "$text" -ForegroundColor Green
    Write-Host "======================================" -ForegroundColor Green
}

function Write-Step($text) {
    Write-Host "`n🚀 $text" -ForegroundColor Cyan
}

function Write-Success($text) {
    Write-Host "✅ $text" -ForegroundColor Green
}

function Write-Warning($text) {
    Write-Host "⚠️ $text" -ForegroundColor Yellow
}

function Write-Error-Msg($text) {
    Write-Host "❌ $text" -ForegroundColor Red
}

Write-Header "ABIC Accounting Backend - Setup"

# --- 1. PRE-REQUISITES CHECK ---
Write-Step "Checking Pre-requisites..."

$dependencies = @(
    @{ Name = "PHP"; Command = "php -v" },
    @{ Name = "Composer"; Command = "composer -V" },
    @{ Name = "MySQL"; Command = "mysql --version" }
)

foreach ($dep in $dependencies) {
    try {
        Invoke-Expression $dep.Command | Out-Null
        Write-Success "$($dep.Name) is installed"
    } catch {
        Write-Error-Msg "$($dep.Name) is not installed. Please install it before proceeding."
        if ($dep.Name -eq "MySQL") { Write-Warning "MySQL CLI is optional but recommended for database setup." }
        else { exit 1 }
    }
}

# --- 2. ENVIRONMENT SETUP ---
Write-Step "Setting up Environment Files..."

if (-not (Test-Path ".env")) {
    if (Test-Path ".env.example") {
        Copy-Item ".env.example" ".env"
        Write-Success "Created .env from .env.example"
    } else {
        Write-Error-Msg ".env.example missing. Cannot create environment file."
    }
} else {
    Write-Success ".env already exists"
}

# --- 3. BACKEND SETUP ---
Write-Step "Setting up Backend (Laravel)..."

if (Test-Path "vendor") {
    Write-Success "Dependencies already installed"
} else {
    Write-Host "Installing composer packages..."
    composer install
    Write-Success "Dependencies installed"
}

# Generate APP_KEY if not set
$envContent = Get-Content .env -Raw
if ($envContent -notmatch "APP_KEY=base64:") {
    Write-Host "Generating application key..." -ForegroundColor Yellow
    php artisan key:generate --force
} else {
    Write-Success "APP_KEY already set"
}

# --- 4. FINISH ---
Write-Header "Backend Setup Complete!"

Write-Host "Next Steps to run the backend:" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Create the Database:" -ForegroundColor White
Write-Host "   Open your MySQL tool and run:"
Write-Host "   CREATE DATABASE IF NOT EXISTS abic_accounting;" -ForegroundColor Yellow
Write-Host ""
Write-Host "2. Run Migrations:" -ForegroundColor White
Write-Host "   php artisan migrate" -ForegroundColor Yellow
Write-Host ""
Write-Host "3. Start the Server:" -ForegroundColor White
Write-Host "   php artisan serve --host=0.0.0.0 --port=8000" -ForegroundColor Yellow
Write-Host ""
Write-Host "Happy coding! 🚀" -ForegroundColor Green
Write-Host ""
