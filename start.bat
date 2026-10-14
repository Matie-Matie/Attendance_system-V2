@echo off
rem ============================================================
rem  SCPLS v2 - Smart Class Presence Log-in System
rem  Monico Rarama National High School
rem  Double-click to start. No tech skills needed.
rem  1. Prepares the database on first run (one-time, ~1 minute)
rem  2. Opens the system in your browser automatically
rem  Keep this window open while using the system.
rem  To stop: press CTRL+C in this window, then close it.
rem ============================================================

title SCPLS v2 - Attendance System
cd /d "%~dp0"
set PORT=8000

goto check_php

:check_php
where php >nul 2>nul
if errorlevel 1 goto no_php
goto check_mysql

:no_php
echo.
echo  [ERROR] PHP was not found on this computer.
echo  Install XAMPP (with PHP and MySQL) first, then double-click again.
echo.
pause
exit /b 1

:check_mysql
set MYSQL=
where mysql >nul 2>nul
if not errorlevel 1 set MYSQL=mysql
if not defined MYSQL if exist "C:\xampp\mysql\bin\mysql.exe" set MYSQL="C:\xampp\mysql\bin\mysql.exe"
if not defined MYSQL goto no_mysql
goto check_db

:no_mysql
echo.
echo  [ERROR] MySQL was not found.
echo  1. Install XAMPP (with MySQL)
echo  2. If XAMPP is installed somewhere unusual, add its
echo     mysql\bin folder to PATH and run this again.
echo  If it still fails, take a photo of this window and send it to Mat.
echo.
pause
exit /b 1

:check_db
if exist "storage\.first-run-done" goto start_server
goto first_db

:first_db
echo.
echo  First-time setup - preparing the database. Please wait...
echo  NOTE: open XAMPP Control Panel and press Start on MySQL first.
echo  (Apache is NOT needed - this system brings its own server.)
echo.
%MYSQL% -h127.0.0.1 -uroot -e "SELECT 1 FROM scpls_v2.users LIMIT 1" >nul 2>nul
if errorlevel 1 goto full_setup
%MYSQL% -h127.0.0.1 -uroot -N -e "SELECT type FROM scpls_v2.sms_logs LIMIT 1" >nul 2>nul
if errorlevel 1 goto half_built
goto seed_check

:half_built
echo  The database looks half-built - rebuilding it cleanly...
echo  (This only happens before first successful setup. No real data exists yet.)
%MYSQL% -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS scpls_v2" >nul 2>nul
:full_setup
echo  Creating database and tables...
%MYSQL% -h127.0.0.1 -uroot < "database\schema.sql"
if errorlevel 1 goto db_fail
:seed_check
%MYSQL% -h127.0.0.1 -uroot -N -e "SELECT COUNT(*) FROM scpls_v2.users" > "%TEMP%\scpls_cnt.txt" 2>nul
set /p USERCNT=<"%TEMP%\scpls_cnt.txt"
del "%TEMP%\scpls_cnt.txt" >nul 2>nul
if "%USERCNT%"=="0" goto do_seed
echo  Database already has accounts - skipping starter data.
goto db_done
:do_seed
echo  Loading starter data (users, students, schedule)...
%MYSQL% -h127.0.0.1 -uroot < "database\seed.sql"
if errorlevel 1 goto db_fail
:db_done
echo DONE > "storage\.first-run-done"
goto start_server

:db_fail
echo.
echo  [ERROR] Could not prepare the database.
echo  1. Open XAMPP Control Panel
echo  2. Press Start on MySQL (Apache is not needed)
echo  3. Double-click this file again
echo  If it still fails, take a photo of this window and send it to Mat.
echo.
pause
exit /b 1

:start_server
echo.
echo  ============================================================
echo   SCPLS v2 is starting...
echo   Opening http://127.0.0.1:%PORT% in your browser.
echo   Logins are in README.md (admin / admin123 to begin).
echo   Keep this window OPEN while using the system.
echo   To stop: press CTRL+C here, then close the window.
echo  ============================================================
echo.
timeout /t 3 /nobreak >nul
start "" "http://127.0.0.1:%PORT%"
call php -S 127.0.0.1:%PORT% -t public
echo.
echo  The system has stopped. You may close this window.
pause
exit /b 0
