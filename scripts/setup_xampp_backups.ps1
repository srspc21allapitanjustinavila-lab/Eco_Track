$ErrorActionPreference = 'Stop'
$taskName = 'EcoTrack Daily Database Backup'
$projectPath = Split-Path -Parent $PSScriptRoot
$phpPath = 'C:\xampp\php\php.exe'
$backupScript = Join-Path $PSScriptRoot 'xampp_database_backup.php'

if (-not (Test-Path -LiteralPath $phpPath) -or -not (Test-Path -LiteralPath $backupScript)) {
    throw 'XAMPP PHP or the EcoTrack backup script could not be found.'
}
if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    throw "A task named '$taskName' already exists. Review it in Task Scheduler before changing it."
}

# The script checks whether a new backup is due each time the task runs.
$actionOptions = @{
    Execute = $phpPath
    Argument = '"{0}" --if-due' -f $backupScript
    WorkingDirectory = $projectPath
}
$triggerOptions = @{
    Once = $true
    At = (Get-Date).AddMinutes(2)
    RepetitionInterval = New-TimeSpan -Hours 1
    RepetitionDuration = New-TimeSpan -Days 3650
}
$action = New-ScheduledTaskAction @actionOptions
$trigger = New-ScheduledTaskTrigger @triggerOptions
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

$taskOptions = @{
    TaskName = $taskName
    Action = $action
    Trigger = $trigger
    Principal = $principal
    Settings = $settings
    Description = 'Backs up the EcoTrack XAMPP database when a daily backup is due.'
}
Register-ScheduledTask @taskOptions | Out-Null
Write-Output "Created task: $taskName"
