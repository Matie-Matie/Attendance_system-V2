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
set "TMPF=%TEMP%\scpls_q.txt"

goto check_php

rem ---------- PHP (PATH first, then the normal XAMPP folder) ----------
:check_php
set PHP=
where php >nul 2>nul
if not errorlevel 1 set PHP=php
if not defined PHP if exist "C:\xampp\php\php.exe" set PHP="C:\xampp\php\php.exe"
if not defined PHP goto no_php
goto check_mysql

:no_php
echo.
echo  [ERROR] PHP was not found on this computer.
echo  Install XAMPP (with PHP and MySQL) in C:\xampp, then double-click again.
echo.
pause
exit /b 1

rem ---------- MySQL client (PATH first, then the normal XAMPP folder) ----------
:check_mysql
set MYSQL=
set MYSQLDUMP=
where mysql >nul 2>nul
if not errorlevel 1 set MYSQL=mysql
if not errorlevel 1 set MYSQLDUMP=mysqldump
if not defined MYSQL if exist "C:\xampp\mysql\bin\mysql.exe" set MYSQL="C:\xampp\mysql\bin\mysql.exe"
if not defined MYSQLDUMP if exist "C:\xampp\mysql\bin\mysqldump.exe" set MYSQLDUMP="C:\xampp\mysql\bin\mysqldump.exe"
if not defined MYSQL goto no_mysql
goto check_mysql_up

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

rem ---------- MySQL must be running (checked on EVERY start) ----------
:check_mysql_up
%MYSQL% -h127.0.0.1 -uroot -e "SELECT 1" >nul 2>nul
if errorlevel 1 goto mysql_down
goto check_running

:mysql_down
echo.
echo  [ERROR] MySQL is not running.
echo  1. Open XAMPP Control Panel
echo  2. Press Start on MySQL (Apache is not needed)
echo  3. Wait until MySQL turns green, then double-click this file again
echo  If it still fails, take a photo of this window and send it to Mat.
echo.
pause
exit /b 1

rem ---------- Already running? Just open the browser ----------
:check_running
netstat -ano | findstr /c:":%PORT% " | findstr /c:"LISTENING" >nul 2>nul
if errorlevel 1 goto check_db
echo.
echo  SCPLS v2 already seems to be running (port %PORT% is in use).
echo  Opening it in your browser. Use the OTHER black window that is
echo  already open - you can close this one.
echo.
start "" "http://127.0.0.1:%PORT%"
timeout /t 5 /nobreak >nul
exit /b 0

rem ---------- Database setup (first run only) ----------
:check_db
if exist "storage\.first-run-done" goto start_server
goto first_db

:first_db
echo.
echo  First-time setup - preparing the database. Please wait...
echo.
set DBCNT=0
%MYSQL% -h127.0.0.1 -uroot -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='scpls_v2'" > "%TMPF%" 2>nul
set /p DBCNT=<"%TMPF%"
del "%TMPF%" >nul 2>nul
if "%DBCNT%"=="0" goto full_setup
rem  The database exists - is it complete?
%MYSQL% -h127.0.0.1 -uroot -e "SELECT 1 FROM scpls_v2.users LIMIT 1" >nul 2>nul
if errorlevel 1 goto half_built
%MYSQL% -h127.0.0.1 -uroot -N -e "SELECT type FROM scpls_v2.sms_logs LIMIT 1" >nul 2>nul
if errorlevel 1 goto half_built
goto seed_check

:half_built
rem  Partly created (an earlier setup stopped halfway). Only rebuild it
rem  when it holds NO attendance records - real data is never deleted.
set RECCNT=0
%MYSQL% -h127.0.0.1 -uroot -N -e "SELECT COUNT(*) FROM scpls_v2.attendance_records" > "%TMPF%" 2>nul
set /p RECCNT=<"%TMPF%"
del "%TMPF%" >nul 2>nul
if not "%RECCNT%"=="0" goto has_data
echo  The database was only partly created last time - rebuilding it cleanly...
if not exist "storage\backups" mkdir "storage\backups"
if defined MYSQLDUMP %MYSQLDUMP% -h127.0.0.1 -uroot --force scpls_v2 > "storage\backups\scpls_v2-before-rebuild-%RANDOM%.sql" 2>nul
%MYSQL% -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS scpls_v2" >nul 2>nul
if errorlevel 1 goto db_fail

:full_setup
echo  Creating database and tables...
%MYSQL% -h127.0.0.1 -uroot < "database\schema.sql"
if errorlevel 1 goto db_fail
goto do_seed

:seed_check
set USERCNT=1
%MYSQL% -h127.0.0.1 -uroot -N -e "SELECT COUNT(*) FROM scpls_v2.users" > "%TMPF%" 2>nul
set /p USERCNT=<"%TMPF%"
del "%TMPF%" >nul 2>nul
rem  No accounts at all = the starter data never loaded; rebuild cleanly.
if "%USERCNT%"=="0" goto half_built
echo  Database already has accounts - keeping it as is.
goto db_done

:do_seed
echo  Loading starter data (users, students, schedule)...
%MYSQL% -h127.0.0.1 -uroot < "database\seed.sql"
if errorlevel 1 goto db_fail

:db_done
echo DONE > "storage\.first-run-done"
goto start_server

:has_data
echo.
echo  [STOP] The scpls_v2 database already has attendance records, but
echo  some of its tables are missing. Nothing was changed, to protect
echo  your data. Take a photo of this window and send it to Mat.
echo.
pause
exit /b 1

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

rem ---------- Start ----------
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
call %PHP% -S 127.0.0.1:%PORT% -t public
echo.
echo  The system has stopped. You may close this window.
pause
exit /b 0
