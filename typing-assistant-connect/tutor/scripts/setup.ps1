param(
    [switch]$LaunchOnly,
    [string]$XamppPath = '',
    [ValidateRange(1024, 65535)][int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$runtimeDirectory = Join-Path $root '.runtime'
$runtimeFile = Join-Path $runtimeDirectory 'runtime.json'
$script:runtime = $null
$script:completed = $false

function Test-Port([int]$Number, [string]$HostName = '127.0.0.1') {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $pending = $client.BeginConnect($HostName, $Number, $null, $null)
        if (-not $pending.AsyncWaitHandle.WaitOne(500)) { return $false }
        $client.EndConnect($pending)
        return $true
    } catch [System.Net.Sockets.SocketException] {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Find-Runtime {
    $candidates = @($XamppPath, (Join-Path $env:SystemDrive 'xampp'))
    if (Test-Path -LiteralPath $runtimeFile) {
        $saved = Get-Content -LiteralPath $runtimeFile -Raw | ConvertFrom-Json
        $candidates += $saved.xampp
    }
    $phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($phpCommand) {
        $candidates += Split-Path -Parent (Split-Path -Parent $phpCommand.Source)
    }
    foreach ($candidate in ($candidates | Where-Object { $_ } | Select-Object -Unique)) {
        $php = Join-Path $candidate 'php\php.exe'
        $mysql = Join-Path $candidate 'mysql\bin\mysqld.exe'
        if ((Test-Path -LiteralPath $php) -and (Test-Path -LiteralPath $mysql)) {
            return @{ php = $php; xampp = $candidate }
        }
    }
    if ($phpCommand -and ((Test-Port 3306) -or (Test-Path -LiteralPath (Join-Path $root 'config\database.local.php')))) {
        return @{ php = $phpCommand.Source; xampp = '' }
    }
    return $null
}

function Prepare-PHP($Runtime) {
    New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
    $phpDirectory = Split-Path -Parent $Runtime.php
    $ini = Join-Path $runtimeDirectory 'php.ini'
    $sourceIni = Join-Path $phpDirectory 'php.ini'
    if (-not (Test-Path -LiteralPath $sourceIni)) {
        $sourceIni = Join-Path $phpDirectory 'php.ini-development'
    }
    if (-not (Test-Path -LiteralPath $sourceIni)) {
        throw 'PHP configuration template not found. Select or install a complete XAMPP runtime.'
    }
    $contents = Get-Content -LiteralPath $sourceIni -Raw
    $extensions = Join-Path $phpDirectory 'ext'
    $contents += "`r`nextension_dir=`"$extensions`"`r`n"
    foreach ($extension in @('pdo_mysql', 'mbstring', 'openssl', 'zip')) {
        $pattern = '(?m)^\s*extension\s*=\s*"?(?:php_)?' + $extension + '(?:\.dll)?"?\s*(?:;[^\r\n]*)?$'
        if (-not [regex]::IsMatch($contents, $pattern)) {
            $contents += "`r`nextension=$extension`r`n"
        }
    }
    [System.IO.File]::WriteAllText($ini, $contents)
    $result = & $Runtime.php -c $ini -r 'exit(version_compare(PHP_VERSION, ''7.4.0'', ''>='') && extension_loaded(''pdo_mysql'') && extension_loaded(''json'') && extension_loaded(''session'') && extension_loaded(''zip'') ? 0 : 1);' 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "PHP 7.4+ with PDO MySQL, JSON, sessions and ZIP is required. $result"
    }
    $Runtime.ini = $ini
    $Runtime | ConvertTo-Json | Set-Content -LiteralPath $runtimeFile -Encoding UTF8
}

function Install-Xampp {
    # Pin the official Apache Friends release and its published checksum.
    $download = 'https://sourceforge.net/projects/xampp/files/XAMPP%20Windows/8.2.12/xampp-windows-x64-8.2.12-0-VS16-installer.exe/download'
    $checksum = 'b6549ee720dff786e95c2fc596c2d5221e4ef72f'
    New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
    $installer = Join-Path $runtimeDirectory 'xampp-installer.exe'
    if ((Test-Path -LiteralPath $installer) -and
        (Get-FileHash -LiteralPath $installer -Algorithm SHA1).Hash -ne $checksum) {
        Remove-Item -LiteralPath $installer
    }
    if (-not (Test-Path -LiteralPath $installer)) {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $client = New-Object System.Net.WebClient
        $transfer = $null
        try {
            $transfer = $client.DownloadFileTaskAsync([uri]$download, $installer)
            $deadline = (Get-Date).AddMinutes(15)
            while (-not $transfer.IsCompleted) {
                [System.Windows.Forms.Application]::DoEvents()
                Start-Sleep -Milliseconds 100
                if ((Get-Date) -gt $deadline) {
                    $client.CancelAsync()
                    throw 'XAMPP download timed out. Check your connection and retry.'
                }
            }
            if ($transfer.IsFaulted) { throw $transfer.Exception.GetBaseException() }
            if ($transfer.IsCanceled) { throw 'XAMPP download was canceled.' }
        } finally {
            $client.Dispose()
            if ($transfer -and $transfer.Status -ne 'RanToCompletion' -and (Test-Path -LiteralPath $installer)) {
                Remove-Item -LiteralPath $installer
            }
        }
    }
    if ((Get-FileHash -LiteralPath $installer -Algorithm SHA1).Hash -ne $checksum) {
        Remove-Item -LiteralPath $installer
        throw 'XAMPP checksum verification failed. The downloaded installer was removed; retry setup.'
    }
    $process = Start-Process -FilePath $installer -Verb RunAs -PassThru
    while (-not $process.HasExited) {
        [System.Windows.Forms.Application]::DoEvents()
        Start-Sleep -Milliseconds 200
    }
    if ($process.ExitCode -ne 0) {
        throw "XAMPP installation failed or was canceled (exit $($process.ExitCode))."
    }
}

function Get-DatabaseEndpoint($Runtime) {
    if (-not (Test-Path -LiteralPath (Join-Path $root 'config\database.local.php'))) {
        return @{ host = '127.0.0.1'; port = 3306 }
    }
    $configuration = Join-Path $root 'config\config.php'
    $result = & $Runtime.php -c $Runtime.ini -r 'require $argv[1]; $s = database_settings(); echo json_encode([''host'' => $s[''host''], ''port'' => $s[''port'']]);' -- $configuration
    if ($LASTEXITCODE -ne 0) { throw 'Could not read the configured database endpoint.' }
    return ($result | ConvertFrom-Json)
}

function Start-Application($Runtime) {
    $database = Get-DatabaseEndpoint $Runtime
    $localDatabase = $database.host -in @('127.0.0.1', 'localhost', '::1')
    if (-not (Test-Port $database.port $database.host) -and $Runtime.xampp -and $localDatabase) {
        $mysqlDirectory = Join-Path $Runtime.xampp 'mysql\bin'
        $myIni = Join-Path $mysqlDirectory 'my.ini'
        if (-not (Test-Path -LiteralPath $myIni)) {
            throw "MySQL configuration missing: $myIni"
        }
        $bindAddress = if ($database.host -eq '::1') { '::1' } else { '127.0.0.1' }
        $databaseProcess = Start-Process -FilePath (Join-Path $mysqlDirectory 'mysqld.exe') `
            -ArgumentList @("--defaults-file=`"$myIni`"", '--standalone', "--bind-address=$bindAddress", "--port=$($database.port)") `
            -WorkingDirectory $Runtime.xampp -PassThru
        $deadline = (Get-Date).AddSeconds(30)
        while (-not (Test-Port $database.port $database.host)) {
            if ($databaseProcess.HasExited -or (Get-Date) -gt $deadline) {
                throw "MySQL did not become ready on port $($database.port). Check the XAMPP mysql\data error log or start your configured database server, then retry."
            }
            Start-Sleep -Milliseconds 250
        }
    }
    if (-not (Test-Port $database.port $database.host)) {
        throw "The configured database server $($database.host):$($database.port) is unreachable. Start it and retry."
    }
    $url = "http://127.0.0.1:$Port"
    $serverProcess = $null
    if (-not (Test-Port $Port)) {
        $serverProcess = Start-Process -FilePath $Runtime.php `
            -ArgumentList @('-c', "`"$($Runtime.ini)`"", '-S', "127.0.0.1:$Port", '-t', "`"$root\public`"", "`"$root\public\router.php`"") `
            -WorkingDirectory $root -PassThru
    }
    $deadline = (Get-Date).AddSeconds(15)
    $ready = $false
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $identity = -join ($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())) | ForEach-Object { $_.ToString('x2') })
    } finally { $sha.Dispose() }
    while ((Get-Date) -lt $deadline) {
        try {
            $health = Invoke-RestMethod -Uri "$url/health.php" -TimeoutSec 2
            if ($health.application -ne 'morse-typing' -or $health.installation -ne $identity) {
                throw "Port $Port belongs to another application. Run setup.ps1 with a different -Port."
            }
            $ready = $true
            break
        } catch [System.Net.WebException] {
            if (-not $serverProcess) { throw "Port $Port is already occupied. Use a different -Port." }
            Start-Sleep -Milliseconds 250
        }
        if ($serverProcess -and $serverProcess.HasExited) { break }
    }
    if (-not $ready) {
        throw 'The local PHP server did not become ready. See its console window for errors.'
    }
    $page = if (Test-Path -LiteralPath (Join-Path $root 'config\installed.lock')) { '/' } else { '/installer.php' }
    Start-Process "$url$page"
}

try {
    if ($LaunchOnly) {
        $script:runtime = Find-Runtime
        if ($script:runtime) {
            Prepare-PHP $script:runtime
            Start-Application $script:runtime
            exit 0
        }
        Write-Host 'Runtime missing. Opening the installation wizard.'
    }
    Add-Type -AssemblyName System.Windows.Forms
    Add-Type -AssemblyName System.Drawing
    [System.Windows.Forms.Application]::EnableVisualStyles()
    $form = New-Object System.Windows.Forms.Form
    $form.Text = 'Morse Typing Tutor - Setup Wizard'
    $form.ClientSize = New-Object System.Drawing.Size(640, 410)
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $heading = New-Object System.Windows.Forms.Label
    $heading.SetBounds(24, 20, 590, 35)
    $heading.Font = New-Object System.Drawing.Font('Segoe UI', 14)
    $description = New-Object System.Windows.Forms.Label
    $description.SetBounds(24, 65, 590, 190)
    $pathLabel = New-Object System.Windows.Forms.Label
    $pathLabel.Text = 'Existing XAMPP folder (optional; auto-detected if empty):'
    $pathLabel.SetBounds(24, 260, 590, 22)
    $pathBox = New-Object System.Windows.Forms.TextBox
    $pathBox.SetBounds(24, 286, 590, 26)
    $pathBox.Text = $XamppPath
    $back = New-Object System.Windows.Forms.Button
    $back.Text = 'Back'
    $back.SetBounds(300, 350, 90, 32)
    $next = New-Object System.Windows.Forms.Button
    $next.SetBounds(400, 350, 100, 32)
    $cancel = New-Object System.Windows.Forms.Button
    $cancel.Text = 'Cancel'
    $cancel.SetBounds(510, 350, 100, 32)
    $form.Controls.AddRange(@($heading, $description, $pathLabel, $pathBox, $back, $next, $cancel))
    $script:step = 1
    function Update-Wizard {
        $back.Enabled = $script:step -gt 1
        $pathBox.Visible = $script:step -eq 2
        $pathLabel.Visible = $script:step -eq 2
        switch ($script:step) {
            1 {
                $heading.Text = '1 of 3 - Welcome'
                $description.Text = "This wizard detects PHP and MySQL and downloads XAMPP only if missing.`r`n`r`nMissing runtime: the verified official XAMPP installer will open its own wizard (administrator approval may be requested). Keep PHP and MySQL selected.`r`n`r`nNo Python or application packages are needed. Google Translate is the only external website integration; bundled languages work offline.`r`n`r`nThis setup is for local use, not public hosting."
                $next.Text = 'Next'
            }
            2 {
                $heading.Text = '2 of 3 - Detect or install prerequisites'
                $description.Text = "Click Check / Install to detect existing software.`r`n`r`nIf XAMPP is missing, it is downloaded automatically over HTTPS and checked against Apache Friends' published SHA-1 checksum before running.`r`n`r`nIf you install into a custom folder, enter that folder below after the XAMPP wizard finishes, then click Check / Install again. Existing installations are never overwritten by this wizard."
                $next.Text = 'Check / Install'
            }
            3 {
                $heading.Text = '3 of 3 - Configure the application'
                $description.Text = "Prerequisites are ready.`r`n`r`nClick Finish to start the local database if needed, start PHP on 127.0.0.1:$Port, and open the browser wizard.`r`n`r`nThe browser wizard tests your database credentials, creates missing tables, and generates starter lessons. Existing progress is preserved.`r`n`r`nUse start.bat next time. Close the PHP server console to stop the website. MySQL remains running for reuse."
                $next.Text = 'Finish'
            }
        }
    }
    $back.Add_Click({ $script:step--; Update-Wizard })
    $cancel.Add_Click({ $form.Close() })
    $next.Add_Click({
        $next.Enabled = $false
        $back.Enabled = $false
        $cancel.Enabled = $false
        $form.ControlBox = $false
        try {
            switch ($script:step) {
                1 { $script:step = 2 }
                2 {
                    $script:XamppPath = $pathBox.Text.Trim()
                    $script:runtime = Find-Runtime
                    if (-not $script:runtime) {
                        $description.Text = 'Downloading/installing XAMPP. Please complete its wizard with PHP and MySQL selected. This can take several minutes.'
                        $form.Refresh()
                        Install-Xampp
                        $script:runtime = Find-Runtime
                        if (-not $script:runtime) {
                            throw 'XAMPP was not detected. Enter its installation folder and retry.'
                        }
                    }
                    Prepare-PHP $script:runtime
                    $script:step = 3
                }
                3 {
                    Start-Application $script:runtime
                    $script:completed = $true
                    $form.Close()
                }
            }
        } catch {
            [System.Windows.Forms.MessageBox]::Show($_.Exception.Message, 'Setup failed', 'OK', 'Error') | Out-Null
        } finally {
            $next.Enabled = $true
            $cancel.Enabled = $true
            $form.ControlBox = $true
            Update-Wizard
        }
    })
    Update-Wizard
    $form.ShowDialog() | Out-Null
    $form.Dispose()
    if (-not $script:completed) { exit 1 }
} catch {
    Write-Error $_
    exit 1
}
