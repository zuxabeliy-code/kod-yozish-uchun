@echo off
setlocal EnableExtensions
rem ADI BURGER kassasini ochadi: printer xizmati ishlab turganini tekshiradi
rem va kassani Chrome App Mode oynasida (manzil satrisiz) ochadi.

set "DIR=%LOCALAPPDATA%\AdiBurger"
set "SERVER="
set "CHROME="
if not exist "%DIR%\kassa.ini" (
  echo Kassa o'rnatilmagan. kassa-ornatish.bat ni ishga tushiring.
  pause
  exit /b 1
)
for /f "usebackq tokens=1,* delims==" %%a in ("%DIR%\kassa.ini") do (
  if /i "%%a"=="SERVER" set "SERVER=%%b"
  if /i "%%a"=="CHROME" set "CHROME=%%b"
)
if not exist "%CHROME%" (
  for %%p in ("%ProgramFiles%\Google\Chrome\Application\chrome.exe" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe") do if exist %%p set "CHROME=%%~p"
)

rem Printer xizmati ishlamayotgan bo'lsa — ishga tushiramiz
tasklist /FI "IMAGENAME eq adi-printer.exe" 2>nul | find /I "adi-printer.exe" >nul
if errorlevel 1 if exist "%DIR%\adi-printer.exe" start "" "%DIR%\adi-printer.exe"

start "" "%CHROME%" --app=%SERVER%/pos/ --user-data-dir="%DIR%\chrome-profile" --no-first-run --no-default-browser-check --disable-translate --disable-features=Translate --start-maximized --autoplay-policy=no-user-gesture-required
exit /b 0
