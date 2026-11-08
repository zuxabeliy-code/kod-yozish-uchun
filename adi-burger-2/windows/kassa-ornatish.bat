@echo off
setlocal EnableExtensions EnableDelayedExpansion
title ADI BURGER 2.0 - Kassani o'rnatish

rem ==========================================================
rem  ADI BURGER 2.0 kassa o'rnatuvchisi (Windows 10/11)
rem  - Printer xizmatini (adi-printer.exe) o'rnatadi va avtozapuskka qo'yadi
rem  - Kassani Chrome App Mode (PWA) oynasida ochadigan yorliq yaratadi
rem  Bu oddiy Chrome oynasi (manzil satrisiz). Mustaqil .exe dastur emas.
rem ==========================================================

set "SERVER=__SERVER__"
set "DIR=%LOCALAPPDATA%\AdiBurger"
set "EXE=%DIR%\adi-printer.exe"

echo.
echo  =============================================
echo     ADI BURGER 2.0 - KASSANI O'RNATISH
echo  =============================================
echo.

rem ---------- 1. Windows versiyasi ----------
for /f "tokens=4-5 delims=. " %%i in ('ver') do set "WINVER=%%i.%%j"
for /f "tokens=1 delims=." %%a in ("%WINVER%") do set "WINMAJOR=%%a"
if "%WINMAJOR%"=="" set "WINMAJOR=0"
if %WINMAJOR% LSS 10 (
  echo  [XATO] Windows 10 yoki 11 kerak. Sizda: %WINVER%
  goto :fail
)
echo  [OK] Windows versiyasi: %WINVER%

rem ---------- 2. Server manzili ----------
if exist "%DIR%\kassa.ini" if "%SERVER%"=="__SERVER__" (
  for /f "usebackq tokens=1,* delims==" %%a in ("%DIR%\kassa.ini") do if /i "%%a"=="SERVER" set "SERVER=%%b"
)
if "%SERVER%"=="__SERVER__" (
  echo.
  echo  Sayt manzilini kiriting ^(masalan: https://adiburger.uz^)
  set /p "SERVER=  Manzil: "
)
if "%SERVER:~-1%"=="/" set "SERVER=%SERVER:~0,-1%"
echo %SERVER% | findstr /b /i "https:// http://" >nul
if errorlevel 1 (
  echo  [XATO] Manzil https:// bilan boshlanishi kerak: %SERVER%
  goto :fail
)
echo  [OK] Server: %SERVER%

rem ---------- 3. Google Chrome ----------
set "CHROME="
for %%p in ("%ProgramFiles%\Google\Chrome\Application\chrome.exe" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe") do (
  if exist %%p if not defined CHROME set "CHROME=%%~p"
)
if not defined CHROME (
  echo  [XATO] Google Chrome topilmadi.
  echo         Chrome'ni o'rnating: https://www.google.com/chrome/
  echo         So'ng shu faylni qayta ishga tushiring.
  start "" "https://www.google.com/chrome/"
  goto :fail
)
echo  [OK] Chrome: %CHROME%

rem ---------- 4. Papka ----------
if not exist "%DIR%" mkdir "%DIR%"
if not exist "%DIR%" (
  echo  [XATO] Papka yaratilmadi: %DIR%
  goto :fail
)

rem ---------- 5. Printer xizmati ----------
echo  ... Printer xizmati o'rnatilmoqda
taskkill /IM adi-printer.exe /F >nul 2>&1
timeout /t 1 /nobreak >nul
if exist "%~dp0adi-printer.exe" (
  copy /Y "%~dp0adi-printer.exe" "%EXE%" >nul
) else (
  powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; try { Invoke-WebRequest -UseBasicParsing -Uri '%SERVER%/windows/adi-printer.exe' -OutFile '%EXE%.new'; exit 0 } catch { Write-Host $_.Exception.Message; exit 1 }"
  if errorlevel 1 (
    echo  [XATO] Printer xizmatini yuklab bo'lmadi: %SERVER%/windows/adi-printer.exe
    echo         Internetni tekshiring yoki adi-printer.exe ni shu fayl yoniga qo'ying.
    goto :fail
  )
  move /Y "%EXE%.new" "%EXE%" >nul
)
if not exist "%EXE%" (
  echo  [XATO] adi-printer.exe topilmadi.
  goto :fail
)
"%EXE%" -setup -origin "%SERVER%" >nul
if errorlevel 1 (
  echo  [XATO] Printer xizmati sozlanmadi. Log: %DIR%\agent.log
  goto :fail
)
rem Windows yoqilganda avtomatik ishga tushadi
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "AdiBurgerPrinter" /t REG_SZ /d "\"%EXE%\"" /f >nul
start "" "%EXE%"
timeout /t 2 /nobreak >nul
powershell -NoProfile -Command "try { $r = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:9123/ping' -TimeoutSec 3; exit 0 } catch { exit 1 }" >nul 2>&1
if errorlevel 1 (
  echo  [OGOHLANTIRISH] Printer xizmati javob bermadi. Antivirus bloklagan bo'lishi mumkin.
  echo                  Log: %DIR%\agent.log
) else (
  echo  [OK] Printer xizmati ishlayapti ^(127.0.0.1:9123^)
)

rem ---------- 6. Chrome: kassa sayti lokal printer xizmatiga ulanishiga ruxsat ----------
rem (Chrome "Local Network Access" so'rovini har safar chiqarmasligi uchun)
reg add "HKCU\Software\Policies\Google\Chrome\LocalNetworkAccessAllowedForUrls" /v 1 /t REG_SZ /d "%SERVER%" /f >nul 2>&1
reg add "HKCU\Software\Policies\Google\Chrome\InsecurePrivateNetworkRequestsAllowedForUrls" /v 1 /t REG_SZ /d "%SERVER%" /f >nul 2>&1

rem ---------- 7. Sozlamalarni saqlash va ochish fayli ----------
> "%DIR%\kassa.ini" echo SERVER=%SERVER%
>> "%DIR%\kassa.ini" echo CHROME=%CHROME%
if exist "%~dp0kassa-ochish.bat" (
  copy /Y "%~dp0kassa-ochish.bat" "%DIR%\kassa-ochish.bat" >nul
) else (
  powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; try { Invoke-WebRequest -UseBasicParsing -Uri '%SERVER%/windows/kassa-ochish.bat' -OutFile '%DIR%\kassa-ochish.bat'; exit 0 } catch { exit 1 }" >nul 2>&1
)
if not exist "%DIR%\kassa-ochish.bat" (
  echo  [XATO] kassa-ochish.bat yuklanmadi.
  goto :fail
)
echo  [OK] Sozlamalar saqlandi: %DIR%\kassa.ini

rem ---------- 8. Ish stolida yorliq ----------
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop')+'\ADI BURGER POS.lnk'); $s.TargetPath='%DIR%\kassa-ochish.bat'; $s.WorkingDirectory='%DIR%'; $s.IconLocation='%CHROME%,0'; $s.WindowStyle=7; $s.Description='ADI BURGER kassa'; $s.Save()"
if errorlevel 1 (
  echo  [OGOHLANTIRISH] Ish stolida yorliq yaratilmadi.
) else (
  echo  [OK] Ish stolida "ADI BURGER POS" yorlig'i yaratildi
)

rem ---------- 9. Windows yoqilganda kassani ochish ----------
echo.
choice /C HY /N /M "  Windows yoqilganda kassa avtomatik ochilsinmi? [H=ha / Y=yo'q]: "
if errorlevel 2 (
  del "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\ADI BURGER POS.lnk" >nul 2>&1
  echo  [OK] Avtomatik ochilmaydi
) else (
  powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Startup')+'\ADI BURGER POS.lnk'); $s.TargetPath='%DIR%\kassa-ochish.bat'; $s.WorkingDirectory='%DIR%'; $s.IconLocation='%CHROME%,0'; $s.WindowStyle=7; $s.Save()"
  echo  [OK] Windows yoqilganda kassa o'zi ochiladi
)

echo.
echo  =============================================
echo   O'RNATISH TUGADI
echo   Kassada: Sozlamalar ^> Printer bo'limida
echo   printer IP manzili, porti va chek o'lchamini
echo   kiriting, "Saqlash" va "Test chek" ni bosing.
echo  =============================================
echo.
start "" "%DIR%\kassa-ochish.bat"
pause
exit /b 0

:fail
echo.
echo  O'rnatish tugallanmadi. Yuqoridagi xatoni tuzatib, qayta ishga tushiring.
pause
exit /b 1
