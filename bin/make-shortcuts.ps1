# Puts two shortcuts on the Desktop of whoever is logged in:
#   "SCPLS QR Scanner"         -> scan.bat  (one click = straight to the QR camera)
#   "SCPLS Attendance System"  -> start.bat (dashboard / login)
#
# Shortcuts, not copies: a .bat COPIED to the Desktop runs from the Desktop
# folder, cannot find the system's files, and just closes. A shortcut always
# runs from the real SCPLS folder.
#
# Runs on every start (cheap). Re-points the shortcuts if the folder moved.
# Never fails start.bat - every error is swallowed.

param([string]$Root = (Split-Path -Parent $PSScriptRoot))

try {
    $shell = New-Object -ComObject WScript.Shell
    $desktops = @([Environment]::GetFolderPath('Desktop')) | Where-Object { $_ -and (Test-Path $_) }

    $items = @(
        @{ Name = 'SCPLS QR Scanner';        Target = 'scan.bat';  Icon = "$env:SystemRoot\System32\shell32.dll,22"; Desc = 'Open the SCPLS QR Scanner' },
        @{ Name = 'SCPLS Attendance System'; Target = 'start.bat'; Icon = "$env:SystemRoot\System32\shell32.dll,13"; Desc = 'Start SCPLS v2 and open the dashboard' }
    )

    foreach ($desk in $desktops) {
        foreach ($it in $items) {
            $target = Join-Path $Root $it.Target
            if (-not (Test-Path $target)) { continue }
            $lnkPath = Join-Path $desk ($it.Name + '.lnk')
            try {
                $lnk = $shell.CreateShortcut($lnkPath)
                if ((Test-Path $lnkPath) -and $lnk.TargetPath -eq $target -and $lnk.WorkingDirectory -eq $Root) { continue }
                $lnk.TargetPath = $target
                $lnk.WorkingDirectory = $Root
                $lnk.IconLocation = $it.Icon
                $lnk.Description = $it.Desc
                $lnk.Save()
            } catch { }
        }
    }
} catch { }
