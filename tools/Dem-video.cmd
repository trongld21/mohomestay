@echo off
setlocal
chcp 65001 >nul
set "PYTHONUTF8=1"
cd /d "%~dp0.."
if not exist ".deploy\counter-venv\Scripts\python.exe" (
    echo Chua co moi truong AI. Can cai dependencies theo docs/PEOPLE_COUNTER_OFFLINE.md.
    pause
    exit /b 1
)
".deploy\counter-venv\Scripts\python.exe" "services\people-counter\video_report.py" --interactive
set "REPORT_EXIT=%ERRORLEVEL%"
pause
exit /b %REPORT_EXIT%
