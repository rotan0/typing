@echo off
setlocal
pushd "%~dp0"
echo Cleaning repository...

REM Preserve installed runtimes, local credentials, database dumps and setup state.
if exist push_output.log del /q push_output.log
if exist .runtime\xampp-installer.exe del /q .runtime\xampp-installer.exe

echo Repo cleaned.
popd
pause
