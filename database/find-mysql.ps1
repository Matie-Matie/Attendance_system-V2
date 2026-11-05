# Finds the RUNNING MySQL/MariaDB server and prints "<client exe>|<port>".
# Prefers XAMPP's server, then one on 3306, then any. Client is "none" when
# the server path can't be read (e.g. it runs as a Windows service).
$found = Get-Process mysqld, mariadbd -ErrorAction SilentlyContinue | ForEach-Object {
    $port = (Get-NetTCPConnection -OwningProcess $_.Id -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalPort -ne 33060 } | Select-Object -First 1).LocalPort
    [pscustomobject]@{ Path = $_.Path; Port = $port }
} | Where-Object { $_.Port }

$pick = $found | Where-Object { $_.Path -match 'xampp' } | Select-Object -First 1
if (-not $pick) { $pick = $found | Where-Object { $_.Port -eq 3306 } | Select-Object -First 1 }
if (-not $pick) { $pick = $found | Select-Object -First 1 }
if (-not $pick) { exit 1 }

$client = 'none'
if ($pick.Path) {
    $dir = Split-Path $pick.Path
    foreach ($name in 'mysql.exe', 'mariadb.exe') {
        $f = Join-Path $dir $name
        if (Test-Path $f) { $client = $f; break }
    }
}
"$client|$($pick.Port)"
