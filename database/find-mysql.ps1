# Tells start.bat which MySQL client to use and on which port.
# Prints exactly one line: "<client exe>|<port>"
#
# SCPLS keeps its data inside XAMPP's MySQL, so XAMPP always wins:
#   1. XAMPP's own server is running        -> its client + the port it listens on
#   2. XAMPP is installed but not running   -> its client + the port from its my.ini
#      (start.bat starts the server on exactly that port)
#   3. No XAMPP at all                      -> any running MySQL/MariaDB, else "none|3306"
#
# Why rule 2 matters: a PC can have a separate MySQL service (e.g. MySQL84 on
# 3307) running. Picking that one made SCPLS try to log in as root on the
# wrong server and stop with "access denied" even though XAMPP was installed.

function Find-XamppDir {
    foreach ($drive in 'C', 'D', 'E', 'F', 'G') {
        $path = "${drive}:\xampp"
        if (Test-Path (Join-Path $path 'mysql\bin\mysqld.exe')) { return $path }
    }
    return $null
}

function Get-XamppPort($xamppDir) {
    $ini = Join-Path $xamppDir 'mysql\bin\my.ini'
    if (-not (Test-Path $ini)) { return 3306 }

    # Only the port inside [mysqld] counts - [client] may hold a different one.
    $section = ''
    foreach ($line in Get-Content $ini) {
        $trim = $line.Trim()
        if ($trim -match '^\[(.+)\]$') { $section = $matches[1].ToLower(); continue }
        if ($section -eq 'mysqld' -and $trim -match '^port\s*=\s*(\d+)') {
            return [int]$matches[1]
        }
    }
    return 3306
}

# Client executable that sits next to a running server (XAMPP preferred).
function Get-Client($serverExe) {
    if (-not $serverExe) { return $null }
    $dir = Split-Path $serverExe
    foreach ($name in 'mysql.exe', 'mariadb.exe') {
        $file = Join-Path $dir $name
        if (Test-Path $file) { return $file }
    }
    return $null
}

$servers = Get-Process mysqld, mariadbd -ErrorAction SilentlyContinue | ForEach-Object {
    # One listening port per server; 33060 is MySQL's X Protocol, not SQL.
    $port = (Get-NetTCPConnection -OwningProcess $_.Id -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalPort -ne 33060 } | Select-Object -First 1).LocalPort
    [pscustomobject]@{ Path = $_.Path; Port = $port }
} | Where-Object { $_.Port }

$xamppDir = Find-XamppDir

if ($xamppDir) {
    $xamppClient = Join-Path $xamppDir 'mysql\bin\mysql.exe'

    # 1. XAMPP's server is the one running - use the port it really listens on.
    $running = $servers | Where-Object { $_.Path -and $_.Path.StartsWith($xamppDir, 'OrdinalIgnoreCase') } |
        Select-Object -First 1
    if ($running) {
        $client = Get-Client $running.Path
        if (-not $client) { $client = $xamppClient }
        "$client|$($running.Port)"
        exit 0
    }

    # 2. Not running (or started with a hidden path): point at XAMPP's own
    #    settings so start.bat starts it there.
    $client = $xamppClient
    if (-not (Test-Path $client)) {
        $client = $servers | ForEach-Object { Get-Client $_.Path } | Select-Object -First 1
    }
    if ($client) { "$client|$(Get-XamppPort $xamppDir)" } else { "none|$(Get-XamppPort $xamppDir)" }
    exit 0
}

# 3. No XAMPP on this PC - fall back to whatever MySQL is running.
$pick = $servers | Where-Object { $_.Port -eq 3306 } | Select-Object -First 1
if (-not $pick) { $pick = $servers | Select-Object -First 1 }
if (-not $pick) { 'none|3306'; exit 0 }

$client = Get-Client $pick.Path
if (-not $client) { 'none|' + $pick.Port } else { "$client|$($pick.Port)" }
