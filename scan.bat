@echo off
rem ============================================================
rem  SCPLS v2 - QR Scanner
rem  Monico Rarama National High School
rem
rem  Double-click to go straight to the scanner page:
rem      http://127.0.0.1:8000/student/scan.php
rem
rem  If the system is already running, this just opens the scanner.
rem  If not, it starts XAMPP's MySQL and the system first (start.bat
rem  does both by itself, including first-run setup), waits for the
rem  server, then opens the scanner. Keep the "Attendance System"
rem  window OPEN.
rem
rem  Tip: put a shortcut to this file on the kiosk PC's Desktop.
rem ============================================================

title SCPLS v2 - QR Scanner
cd /d "%~dp0"
set PORT=8000
set "SCANURL=http://127.0.0.1:%PORT%/student/scan.php"

rem ---------- 1. Already running? Open the scanner right away ----------
:check_running
netstat -ano | findstr /c:":%PORT% " | findstr /c:"LISTENING" >nul 2>nul
if not errorlevel 1 goto open_scanner

rem ---------- 2. Not running: start the system, then wait for it ----------
rem  start.bat starts XAMPP's MySQL, prepares the database on a brand-new
rem  PC, and serves the site. SCPLS_NO_BROWSER keeps it from also opening
rem  the dashboard - this window is only for the scanner.
echo.
echo  SCPLS v2 is not running yet - starting XAMPP's MySQL and the
echo  system now. On a brand-new PC the first setup takes about a minute.
echo  A second window will open. Keep that window OPEN while using
echo  the system, and do not close it.
echo.
set "SCPLS_NO_BROWSER=1"
start "" "%~dp0start.bat"
set TRIES=0

:wait_loop
timeout /t 1 /nobreak >nul
netstat -ano | findstr /c:":%PORT% " | findstr /c:"LISTENING" >nul 2>nul
if not errorlevel 1 goto open_scanner
set /a TRIES+=1
if %TRIES% LSS 180 goto wait_loop
goto still_down

rem ---------- 3. Open the scanner ----------
:open_scanner
echo  Opening the QR Scanner...
start "" "%SCANURL%"
rem  Give the browser a moment to open, then close this helper window.
timeout /t 2 /nobreak >nul
exit /b 0

rem ---------- 4. It never came up ----------
:still_down
echo.
echo  [STOP] The system did not start, so the scanner cannot open.
echo  Look at the OTHER window (SCPLS v2 - Attendance System): it shows
echo  the actual error. Take a photo of that window and send it to Mat.
echo.
pause
exit /b 1
