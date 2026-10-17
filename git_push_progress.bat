@echo off
setlocal
pushd "%~dp0"
git rev-parse --is-inside-work-tree >nul 2>&1
if errorlevel 1 (
    echo This folder is not a Git repository. Initialize Git and configure a remote first.
    popd
    pause
    exit /b 1
)

git add .
if errorlevel 1 goto failed
git diff --cached --quiet
if errorlevel 1 (
    git commit -m "Update typing tutor" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
    if errorlevel 1 goto failed
)
echo Pushing current branch without force. Git will display progress below.
git push --progress
if errorlevel 1 goto failed
echo Push completed.
popd
pause
exit /b 0

:failed
echo Git operation failed. Check the output above.
popd
pause
exit /b 1
