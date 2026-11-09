@echo off
setlocal EnableExtensions
title ADI BURGER 2.0 - Kassani o'rnatish

rem ==========================================================
rem  ADI BURGER 2.0 kassa o'rnatuvchisi (Windows 10/11)
rem  Bu fayl YAGONA kerakli fayl: qolganini o'zi yaratadi yoki saytdan yuklaydi.
rem  - Printer xizmatini (adi-printer.exe) o'rnatadi va Windows bilan birga ishga tushiradi
rem  - Kassani Chrome App Mode (PWA) oynasida ochadigan yorliq yaratadi
rem  Bu oddiy Chrome oynasi (manzil satrisiz). Mustaqil .exe dastur emas.
rem ==========================================================

set "SERVER=__SERVER__"
if "%SERVER:~0,2%"=="__" set "SERVER="
set "DIR=%LOCALAPPDATA%\AdiBurger"
set "EXE=%DIR%\adi-printer.exe"
set "OCHISH=%DIR%\kassa-ochish.bat"

echo.
echo  =============================================
echo     ADI BURGER 2.0 - KASSANI O'RNATISH
echo  =============================================
echo.

rem ---------- 1. Windows versiyasi ----------
for /f "tokens=4-5 delims=. " %%i in ('ver') do set "WINVER=%%i.%%j"
for /f "tokens=1 delims=." %%a in ("%WINVER%") do set "WINMAJOR=%%a"
if "%WINMAJOR%"=="" set "WINMAJOR=0"
if %WINMAJOR% LSS 10 goto :oldwin
echo  [OK] Windows versiyasi: %WINVER%
goto :server

:oldwin
echo  [XATO] Windows 10 yoki 11 kerak. Sizda: %WINVER%
goto :fail

rem ---------- 2. Sayt manzili ----------
:server
if not "%SERVER%"=="" goto :normalize
if not exist "%DIR%\kassa.ini" goto :ask
for /f "usebackq tokens=1,* delims==" %%a in ("%DIR%\kassa.ini") do if /i "%%a"=="SERVER" set "SERVER=%%b"
if not "%SERVER%"=="" goto :normalize

:ask
echo  Sayt manzilini kiriting. Masalan: https://adiburger.uz
echo  Agar sayt papkada turgan bo'lsa: https://adiburger.uz/Adi
echo  (oxiriga /pos yoki /admin yozmang)
echo.
set /p "SERVER=  Manzil: "

rem Manzilni tozalash: oxiridagi / va /pos /admin /install /api /index.php olib tashlanadi
:normalize
if "%SERVER%"=="" goto :badserver
if "%SERVER:~-1%"=="/" set "SERVER=%SERVER:~0,-1%" & goto :normalize
if "%SERVER:~-1%"==" " set "SERVER=%SERVER:~0,-1%" & goto :normalize
if /i "%SERVER:~-4%"=="/pos" set "SERVER=%SERVER:~0,-4%" & goto :normalize
if /i "%SERVER:~-4%"=="/api" set "SERVER=%SERVER:~0,-4%" & goto :normalize
if /i "%SERVER:~-6%"=="/admin" set "SERVER=%SERVER:~0,-6%" & goto :normalize
if /i "%SERVER:~-8%"=="/install" set "SERVER=%SERVER:~0,-8%" & goto :normalize
if /i "%SERVER:~-10%"=="/index.php" set "SERVER=%SERVER:~0,-10%" & goto :normalize
echo %SERVER%| findstr /b /i "https:// http://" >nul
if errorlevel 1 goto :badserver
echo  [OK] Sayt manzili: %SERVER%
goto :chrome

:badserver
echo  [XATO] Sayt manzili kiritilmadi yoki noto'g'ri. U http:// yoki https:// bilan boshlanishi kerak: %SERVER%
set "SERVER="
goto :fail

rem ---------- 3. Google Chrome ----------
:chrome
set "CHROME="
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not defined CHROME if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not defined CHROME if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" set "CHROME=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
if defined CHROME goto :chrome_ok
echo  [XATO] Google Chrome topilmadi.
echo         Chrome'ni o'rnating: https://www.google.com/chrome/
echo         So'ng shu faylni qayta ishga tushiring.
start "" "https://www.google.com/chrome/"
goto :fail
:chrome_ok
echo  [OK] Chrome: %CHROME%

rem ---------- 4. Sayt ochiladimi (haqiqiy tekshiruv) ----------
echo  ... Sayt tekshirilmoqda
powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; try { $r=Invoke-WebRequest -UseBasicParsing -Uri '%SERVER%/api/?r=ping' -TimeoutSec 20; if ($r.Content -match 'ok.:true') { exit 0 } else { exit 2 } } catch { Write-Host ('        ' + $_.Exception.Message); exit 1 }"
if errorlevel 2 goto :notadi
if errorlevel 1 goto :nosite
echo  [OK] Sayt javob berdi: ADI BURGER 2.0
goto :folder

:nosite
echo  [XATO] Saytga ulanib bo'lmadi: %SERVER%
echo         Internetni va sayt manzilini tekshiring.
set "SERVER="
goto :fail

:notadi
echo  [XATO] Bu manzilda ADI BURGER 2.0 topilmadi: %SERVER%
echo         Sayt papkada o'rnatilgan bo'lsa, papka nomini ham yozing (masalan https://sayt.uz/Adi).
echo         Admin panel manzilidan "/admin/" qismini olib tashlab yozing.
set "SERVER="
goto :fail

rem ---------- 5. Papka ----------
:folder
if not exist "%DIR%" mkdir "%DIR%"
if exist "%DIR%" goto :agent
echo  [XATO] Papka yaratilmadi: %DIR%
goto :fail

rem ---------- 6. Printer xizmati ----------
:agent
echo  ... Printer xizmati o'rnatilmoqda
taskkill /IM adi-printer.exe /F >nul 2>&1
timeout /t 1 /nobreak >nul
if exist "%~dp0adi-printer.exe" goto :copyexe
call :download "%SERVER%/windows/adi-printer.exe" "%EXE%.new"
if errorlevel 1 goto :noexe
move /Y "%EXE%.new" "%EXE%" >nul
goto :exeok
:copyexe
copy /Y "%~dp0adi-printer.exe" "%EXE%" >nul
goto :exeok
:noexe
echo  [XATO] Printer xizmatini yuklab bo'lmadi: %SERVER%/windows/adi-printer.exe
echo         adi-printer.exe ni admin paneldan (POS va printer bo'limi) yuklab, shu fayl yoniga qo'ying va qayta ishga tushiring.
goto :fail
:exeok
if exist "%EXE%" goto :setup
echo  [XATO] adi-printer.exe topilmadi.
goto :fail
:setup
"%EXE%" -setup -origin "%SERVER%" >nul
if errorlevel 1 goto :setupfail
rem Windows yoqilganda printer xizmati avtomatik ishga tushadi
reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "AdiBurgerPrinter" /t REG_SZ /d "\"%EXE%\"" /f >nul
start "" "%EXE%"
timeout /t 2 /nobreak >nul
powershell -NoProfile -Command "try { Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:9123/ping' -TimeoutSec 3 | Out-Null; exit 0 } catch { exit 1 }" >nul 2>&1
if errorlevel 1 goto :agentwarn
echo  [OK] Printer xizmati ishlayapti - 127.0.0.1:9123
goto :policy
:agentwarn
echo  [OGOHLANTIRISH] Printer xizmati javob bermadi. Antivirus bloklagan bo'lishi mumkin.
echo                  Log: %DIR%\agent.log
goto :policy
:setupfail
echo  [XATO] Printer xizmati sozlanmadi. Log: %DIR%\agent.log
goto :fail

rem ---------- 7. Chrome: kassa sayti lokal printer xizmatiga ulanishiga ruxsat ----------
:policy
reg add "HKCU\Software\Policies\Google\Chrome\LocalNetworkAccessAllowedForUrls" /v 1 /t REG_SZ /d "%SERVER%" /f >nul 2>&1
reg add "HKCU\Software\Policies\Google\Chrome\InsecurePrivateNetworkRequestsAllowedForUrls" /v 1 /t REG_SZ /d "%SERVER%" /f >nul 2>&1

rem ---------- 8. Sozlamalarni saqlash va kassa-ochish.bat ni YARATISH (alohida fayl kerak emas) ----------
> "%DIR%\kassa.ini" echo SERVER=%SERVER%
>> "%DIR%\kassa.ini" echo CHROME=%CHROME%
> "%OCHISH%" echo @echo off
>> "%OCHISH%" echo rem ADI BURGER kassasini ochadi (o'rnatuvchi yaratgan fayl)
>> "%OCHISH%" echo tasklist /FI "IMAGENAME eq adi-printer.exe" 2^>nul ^| find /I "adi-printer.exe" ^>nul
>> "%OCHISH%" echo if errorlevel 1 start "" "%EXE%"
>> "%OCHISH%" echo start "" "%CHROME%" --app=%SERVER%/pos/ --user-data-dir="%DIR%\chrome-profile" --no-first-run --no-default-browser-check --disable-translate --disable-features=Translate --start-maximized --autoplay-policy=no-user-gesture-required
>> "%OCHISH%" echo exit /b 0
if exist "%OCHISH%" goto :shortcut
echo  [XATO] kassa-ochish.bat yaratilmadi. %DIR% papkasiga yozish ruxsatini tekshiring.
goto :fail

rem ---------- 9. Ish stolida yorliq ----------
:shortcut
echo  [OK] Sozlamalar saqlandi: %DIR%\kassa.ini
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Desktop')+'\ADI BURGER POS.lnk'); $s.TargetPath='%OCHISH%'; $s.WorkingDirectory='%DIR%'; $s.IconLocation='%CHROME%,0'; $s.WindowStyle=7; $s.Description='ADI BURGER kassa'; $s.Save()"
if errorlevel 1 goto :noshortcut
echo  [OK] Ish stolida "ADI BURGER POS" yorlig'i yaratildi
goto :autostart
:noshortcut
echo  [OGOHLANTIRISH] Ish stolida yorliq yaratilmadi. Kassani shu fayl bilan oching: %OCHISH%

rem ---------- 10. Windows yoqilganda kassani ochish ----------
:autostart
echo.
choice /C HY /N /M "  Windows yoqilganda kassa avtomatik ochilsinmi? [H=ha / Y=yo'q]: "
if errorlevel 2 goto :noauto
powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath('Startup')+'\ADI BURGER POS.lnk'); $s.TargetPath='%OCHISH%'; $s.WorkingDirectory='%DIR%'; $s.IconLocation='%CHROME%,0'; $s.WindowStyle=7; $s.Save()"
echo  [OK] Windows yoqilganda kassa o'zi ochiladi
goto :done
:noauto
del "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\ADI BURGER POS.lnk" >nul 2>&1
echo  [OK] Avtomatik ochilmaydi

:done
echo.
echo  =============================================
echo   O'RNATISH TUGADI
echo   Kassa ochilganda admin login va parol bilan
echo   qurilmani bir marta ulang. Keyin: Sozlamalar
echo   ^> Printer: IP manzil, port va chek o'lchamini
echo   kiriting, "Saqlash" va "Test chek" ni bosing.
echo  =============================================
echo.
start "" "%OCHISH%"
pause
exit /b 0

rem ---------- Fayl yuklash: avval curl, bo'lmasa PowerShell ----------
:download
curl.exe -L -f -s --max-time 120 -o "%~2" "%~1" >nul 2>&1
if not errorlevel 1 if exist "%~2" exit /b 0
powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; try { Invoke-WebRequest -UseBasicParsing -Uri '%~1' -OutFile '%~2'; exit 0 } catch { Write-Host ('        ' + $_.Exception.Message); exit 1 }"
exit /b %errorlevel%

:fail
echo.
echo  O'rnatish tugallanmadi. Yuqoridagi xatoni tuzatib, faylni qayta ishga tushiring.
pause
exit /b 1
