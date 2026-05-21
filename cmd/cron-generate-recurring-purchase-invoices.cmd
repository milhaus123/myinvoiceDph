@echo off
REM ============================================================================
REM  cron-generate-recurring-purchase-invoices.cmd — generovani pravidelnych nakupnich faktur
REM  Frekvence: 1x denne, doporuceno 06:35 (5 min po recurring vydanych fakturach)
REM
REM  Prochazi sablony pravidelnych nakupnich faktur kde status='active'
REM  a next_run_date <= dnes a vygeneruje nakupni fakturu. Podle per-sablona
REM  flagu auto_issue rovnou prechod draft -> received.
REM
REM  Volitelne argumenty:
REM    --dry-run       jen vypise, co by se vygenerovalo
REM
REM  Task Scheduler (kazdy den 06:35):
REM    schtasks /create /tn "MyInvoice Recurring Purchase" ^
REM      /tr "%~f0" /sc daily /st 06:35 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
set "LOG_DIR=%PROJECT_ROOT%\log\cron"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-generate-recurring-purchase-invoices.php" %* >> "%LOG_DIR%\generate-recurring-purchase-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
