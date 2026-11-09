@echo off
rem ============================================================
rem  SCPLS v2 - Smart Class Presence Log-in System
rem  Monico Rarama National High School
rem  Double-click to start. No tech skills needed.
rem  1. Starts XAMPP's MySQL by itself when it is not running
rem     (Apache is NOT needed - PHP's built-in server runs the site)
rem  2. Prepares the database on first run (one-time, ~1 minute)
rem  3. Opens the system in your browser automatically
rem  Keep this window open while using the system.
rem  To stop: press CTRL+C in this window, then close it.
rem ============================================================

title SCPLS v2 - Attendance System
cd /d "%~dp0"
set PORT=8000
set "TMPF=%TEMP%\scpls_q.txt"
set "LOG=%TEMP%\scpls_setup_log.txt"

goto check_mysql

rem ---------- PHP (XAMPP first, then PATH) ----------
rem  XAMPP's PHP has the MySQL driver switched on. A separate PHP on PATH
rem  (WinGet/zip install, no php.ini) often does NOT - the site then shows
rem  "temporarily unavailable" with "could not find driver" in this window.
:check_php
set PHP=
set PHPEXT=
if defined SRVDIR if exist "%SRVDIR%..\..\php\php.exe" for %%I in ("%SRVDIR%..\..\php\php.exe") do set PHP="%%~fI"
for %%D in (C D E) do if not defined PHP if exist "%%D:\xampp\php\php.exe" set PHP="%%D:\xampp\php\php.exe"
if defined PHP goto php_driver
where php >nul 2>nul
if errorlevel 1 goto no_php
set PHP=php
rem  PATH PHP: switch on any missing extensions from its own ext folder.
set PHPDIR=
for /f "delims=" %%P in ('php -r "echo dirname(realpath(PHP_BINARY));" 2^>nul') do set "PHPDIR=%%P"
for %%E in (pdo_mysql mbstring openssl) do (
    php -m 2>nul | findstr /i /r /c:"^%%E" >nul || call set "PHPEXT=%%PHPEXT%% -d extension=%%E"
)
if defined PHPEXT if defined PHPDIR set PHP=php -d extension_dir="%PHPDIR%\ext"%PHPEXT%

:php_driver
rem  (php -m ends lines with LF only, so findstr /x never matches - use ^)
%PHP% -m 2>nul | findstr /i /r /c:"^pdo_mysql" >nul
if errorlevel 1 goto no_driver
goto check_mysql_up

:no_php
echo.
echo  [ERROR] PHP was not found on this computer.
echo  Install XAMPP (with PHP and MySQL) in C:\xampp, then double-click again.
echo.
pause
exit /b 1

:no_driver
echo.
echo  [ERROR] The PHP on this computer cannot talk to MySQL (pdo_mysql is off).
echo  PHP used: %PHP%
echo  Install XAMPP in C:\xampp (it includes the right PHP), then double-click
echo  this file again. If it still fails, take a photo and send it to Mat.
echo.
pause
exit /b 1

rem ---------- MySQL client + port ----------
rem  1. Ask Windows which MySQL/MariaDB server is RUNNING (XAMPP preferred)
rem     and on which port - XAMPP may sit on 3307 if 3306 was taken.
rem  2. Use the client next to that server; else XAMPP on C:/D:/E:; else PATH.
rem  A "mysql" on PATH may be a .cmd wrapper, so it is CALLed (running a .cmd
rem  without CALL ends this script = the window just closes).
:check_mysql
set MYSQL=
set MYSQLDUMP=
set SRVCLIENT=
set SRVDIR=
set DBPORT=
for /f "usebackq tokens=1,2 delims=|" %%A in (`powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0database\find-mysql.ps1" 2^>nul`) do (
    set "SRVCLIENT=%%A"
    set "DBPORT=%%B"
)
if not defined DBPORT set DBPORT=3306
if "%SRVCLIENT%"=="none" set SRVCLIENT=
if defined SRVCLIENT if not exist "%SRVCLIENT%" set SRVCLIENT=
if not defined SRVCLIENT goto client_fallback
for %%F in ("%SRVCLIENT%") do set "SRVDIR=%%~dpF"
set MYSQL="%SRVCLIENT%" -P%DBPORT%
if exist "%SRVDIR%mysqldump.exe" set MYSQLDUMP="%SRVDIR%mysqldump.exe" -P%DBPORT%
goto mysql_found

:client_fallback
for %%D in (C D E) do if not defined MYSQL if exist "%%D:\xampp\mysql\bin\mysql.exe" (
    set MYSQL="%%D:\xampp\mysql\bin\mysql.exe" -P%DBPORT%
    set MYSQLDUMP="%%D:\xampp\mysql\bin\mysqldump.exe" -P%DBPORT%
)
if defined MYSQL goto mysql_found
where mysql >nul 2>nul
if not errorlevel 1 set MYSQL=call mysql -P%DBPORT%
if not errorlevel 1 set MYSQLDUMP=call mysqldump -P%DBPORT%

:mysql_found
if not defined MYSQL goto no_mysql
rem  The PHP app reads this so it talks to the same port.
set SCPLS_DB_PORT=%DBPORT%
goto check_php

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
if exist "%LOG%" del "%LOG%" >nul 2>nul
%MYSQL% -h127.0.0.1 -uroot -e "SELECT 1" >nul 2>"%LOG%"
if errorlevel 1 goto try_start_mysql
goto check_running

rem ---------- Not running: start XAMPP's MySQL for the teacher ----------
rem  One double-click is the whole promise of this file, so never send the
rem  teacher to the XAMPP Control Panel for the normal case. Apache is not
rem  needed: the site runs on PHP's own server below.
rem  AUTOSTART keeps this bounded - after one attempt a real failure falls
rem  through to the error screen instead of looping forever.
:try_start_mysql
if "%AUTOSTART%"=="1" goto mysql_down
set AUTOSTART=1
echo.
echo  MySQL is not running - starting XAMPP's MySQL automatically...
call :launch_xampp_mysql
set WAITED=0
:mysql_wait
timeout /t 2 /nobreak >nul
%MYSQL% -h127.0.0.1 -uroot -e "SELECT 1" >nul 2>"%LOG%"
if not errorlevel 1 goto check_mysql
set /a WAITED+=2
if %WAITED% LSS 45 goto mysql_wait
rem  Still nothing: look once more with fresh eyes (a teacher's XAMPP may
rem  be configured on port 3307 instead of 3306).
goto check_mysql

:launch_xampp_mysql
rem  Order: the XAMPP that owns the detected server folder, a standard
rem  C:/D:/E:\xampp install, then a MySQL/MariaDB Windows service.
set "XAMPPDIR="
if defined SRVDIR if exist "%SRVDIR%..\..\mysql\bin\mysqld.exe" for %%I in ("%SRVDIR%..\..") do set "XAMPPDIR=%%~fI"
for %%D in (C D E) do if not defined XAMPPDIR if exist "%%D:\xampp\mysql\bin\mysqld.exe" set "XAMPPDIR=%%D:\xampp"
if defined XAMPPDIR goto launch_xampp_mysqld
net start mysql >nul 2>nul
if not errorlevel 1 exit /b 0
net start mariadb >nul 2>nul
exit /b 0

:launch_xampp_mysqld
echo  Found XAMPP at %XAMPPDIR%
rem  Run from the XAMPP folder, exactly like XAMPP's own mysql_start.bat.
rem  No parenthesised blocks here: a XAMPP path like "Program Files (x86)"
rem  would close the block early and break the start line.
set "XAMPPINI=%XAMPPDIR%\mysql\bin\my.ini"
if not exist "%XAMPPINI%" set "XAMPPINI="
pushd "%XAMPPDIR%"
if defined XAMPPINI start "XAMPP MySQL" /min "%XAMPPDIR%\mysql\bin\mysqld.exe" --defaults-file="%XAMPPINI%" --standalone --console
if not defined XAMPPINI start "XAMPP MySQL" /min "%XAMPPDIR%\mysql\bin\mysqld.exe" --standalone --console
popd
exit /b 0

:mysql_down
echo.
findstr /c:"1045" "%LOG%" >nul 2>nul
if not errorlevel 1 goto mysql_password
echo  [ERROR] Could not connect to MySQL on port %DBPORT%.
echo  SCPLS v2 already tried to start XAMPP's MySQL by itself, but it did
echo  not come up. Apache is not needed - only MySQL.
echo  ---- MySQL said: ----
if exist "%LOG%" type "%LOG%"
echo  ---------------------
echo  1. Open XAMPP Control Panel
echo  2. Press Start on MySQL
echo  3. If MySQL shows a red error, read it: the database files may be
echo     locked by another MySQL, or the XAMPP folder was moved
echo  4. Double-click this file again
echo  If it still fails, take a photo of this window and send it to Mat.
echo.
pause
exit /b 1

:mysql_password
echo  [ERROR] MySQL is running on port %DBPORT%, but it refused user "root"
echo  with no password (someone set a root password).
echo  ---- MySQL said: ----
type "%LOG%"
echo  ---------------------
echo  Take a photo of this window and send it to Mat.
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
if not defined SCPLS_NO_BROWSER start "" "http://127.0.0.1:%PORT%"
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
if exist "%LOG%" del "%LOG%" >nul 2>nul
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
%MYSQL% -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS scpls_v2" >nul 2>>"%LOG%"
if not errorlevel 1 goto full_setup
rem  XAMPP refuses the DROP ("Directory not empty", errno 41) when stray
rem  files were left in its data\scpls_v2 folder. Only reached when there
rem  are NO attendance records, so it is safe to clear that one folder.
echo  Clearing leftover database files...
set DATADIR=
%MYSQL% -h127.0.0.1 -uroot -N -r -e "SELECT @@datadir" > "%TMPF%" 2>>"%LOG%"
set /p DATADIR=<"%TMPF%"
del "%TMPF%" >nul 2>nul
if not defined DATADIR goto db_fail
set "DATADIR=%DATADIR:/=\%"
if not "%DATADIR:~-1%"=="\" set "DATADIR=%DATADIR%\"
if exist "%DATADIR%scpls_v2\" rmdir /s /q "%DATADIR%scpls_v2" 2>>"%LOG%"
%MYSQL% -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS scpls_v2" >nul 2>>"%LOG%"
if errorlevel 1 goto db_fail

:full_setup
echo  Creating database and tables...
rem  Clear any "ghost" tables MySQL still remembers from a broken earlier
rem  setup (they cause "already exists" / "Tablespace exists" errors).
%MYSQL% -h127.0.0.1 -uroot -e "CREATE DATABASE IF NOT EXISTS scpls_v2 DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci; SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS scpls_v2.audit_logs, scpls_v2.settings, scpls_v2.sms_logs, scpls_v2.attendance_records, scpls_v2.attendance_sessions, scpls_v2.schedule_entries, scpls_v2.students, scpls_v2.semesters, scpls_v2.school_years, scpls_v2.sections, scpls_v2.subjects, scpls_v2.teachers, scpls_v2.users, scpls_v2.roles" >nul 2>>"%LOG%"
%MYSQL% -h127.0.0.1 -uroot < "database\schema.sql" 2>>"%LOG%"
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
%MYSQL% -h127.0.0.1 -uroot < "database\seed.sql" 2>>"%LOG%"
if errorlevel 1 goto db_fail

:db_done
if not exist "storage" mkdir "storage"
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
echo  ---- MySQL said: ----
if exist "%LOG%" type "%LOG%"
echo  ---------------------
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
if not defined SCPLS_NO_BROWSER start "" "http://127.0.0.1:%PORT%"
call %PHP% -S 127.0.0.1:%PORT% -t public
echo.
echo  The system has stopped. You may close this window.
pause
exit /b 0
