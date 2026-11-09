# Opens the SCPLS site in Google Chrome as soon as the local PHP server
# answers. Runs hidden from start.bat so the black window never freezes
# while waiting.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File bin\open-browser.ps1 -Port 8000
#
# Chrome is preferred; if it is not installed, the computer's default browser
# is used instead. Nothing is opened twice - the script runs once per start.

param(
    [int]$Port = 8000,
    [string]$Url = "",
    [int]$WaitSeconds = 60
)

if (-not $Url) { $Url = "http://127.0.0.1:$Port" }
$url = $Url

# ---- 1. Wait until the server is really listening -------------------------
$deadline = (Get-Date).AddSeconds($WaitSeconds)
while ((Get-Date) -lt $deadline) {
    $client = $null
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $client.Connect('127.0.0.1', $Port)
        break
    } catch {
        Start-Sleep -Milliseconds 400
    } finally {
        if ($client) { $client.Close() }
    }
}

# ---- 2. Find Google Chrome ------------------------------------------------
$chromePaths = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
)

$chrome = $chromePaths | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

# Chrome installed per-user somewhere unusual - ask the registry.
if (-not $chrome) {
    $keys = @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe',
        'HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe',
        'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\App Paths\chrome.exe'
    )
    foreach ($key in $keys) {
        try {
            $path = (Get-ItemProperty -Path $key -ErrorAction Stop).'(default)'
            if ($path -and (Test-Path $path)) { $chrome = $path; break }
        } catch { }
    }
}

# ---- 3. Open it -----------------------------------------------------------
if ($chrome) {
    Start-Process -FilePath $chrome -ArgumentList $url
} else {
    Start-Process $url   # no Chrome on this computer - default browser
}
