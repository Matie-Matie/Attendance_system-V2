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
# Order: Google Chrome -> the computer's default browser (Edge on a Windows
# PC that never installed Chrome) -> Edge itself, in case the default handler
# is missing or broken. Nothing here requires Chrome to exist.
if ($chrome) {
    try {
        Start-Process -FilePath $chrome -ArgumentList $url
        return
    } catch { }
}

try {
    Start-Process $url
} catch {
    $edgePaths = @(
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
    )
    $edge = $edgePaths | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
    if ($edge) { Start-Process -FilePath $edge -ArgumentList $url }
}
