@echo off
setlocal
pushd "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File "%~dp0scripts\setup.ps1" -LaunchOnly
set "result=%errorlevel%"
popd
if not "%result%"=="0" pause
exit /b %result%
