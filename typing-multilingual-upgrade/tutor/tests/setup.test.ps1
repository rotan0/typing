$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile((Join-Path $root 'scripts\setup.ps1'), [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw ($errors | Out-String) }
foreach ($function in $ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $false)) {
    . ([scriptblock]::Create($function.Extent.Text))
}
Write-Host 'PASS: Windows wizard parses'
$XamppPath = 'C:\xampp'
$runtimeDirectory = Join-Path ([IO.Path]::GetTempPath()) ('typing-setup-test-' + [guid]::NewGuid().ToString('N'))
$runtimeFile = Join-Path $runtimeDirectory 'runtime.json'
$runtime = Find-Runtime
if (-not $runtime) { throw 'Test requires an existing XAMPP installation; no software is installed by this test.' }
try {
    Prepare-PHP $runtime
    if (-not (Test-Path -LiteralPath $runtime.ini)) { throw 'App-specific php.ini was not created.' }
    $saved = Get-Content -LiteralPath $runtimeFile -Raw | ConvertFrom-Json
    if ($saved.php -ne $runtime.php) { throw 'Runtime cache did not preserve the PHP path.' }
    Write-Host 'PASS: Existing XAMPP detection, private PHP configuration, required extensions, runtime cache'
    if (Test-Port 0) { throw 'Port zero must not be detected as a listening database.' }
    Write-Host 'PASS: Closed port detection'
    $originalRoot = $root
    $testConfiguration = Join-Path $runtimeDirectory 'config'
    New-Item -ItemType Directory -Path $testConfiguration | Out-Null
    Copy-Item -LiteralPath (Join-Path $root 'config\config.php') -Destination (Join-Path $testConfiguration 'config.php')
    [IO.File]::WriteAllText((Join-Path $testConfiguration 'database.local.php'), "<?php return ['host' => '127.0.0.1', 'port' => 3307, 'password' => 'not-exported'];")
    try {
        $root = $runtimeDirectory
        $endpoint = Get-DatabaseEndpoint $runtime
        if ($endpoint.host -ne '127.0.0.1' -or $endpoint.port -ne 3307 -or $endpoint.PSObject.Properties.Name -contains 'password') {
            throw 'Database endpoint helper must read the saved host/port without exposing credentials.'
        }
        Write-Host 'PASS: Saved endpoint is read by PHP without exporting credentials'
    } finally {
        $root = $originalRoot
        Remove-Item -LiteralPath (Join-Path $testConfiguration 'config.php')
        Remove-Item -LiteralPath (Join-Path $testConfiguration 'database.local.php')
        Remove-Item -LiteralPath $testConfiguration
    }

    $Port = 8080
    $script:launches = @()
    $script:wrongApplication = $false
    function Test-Port([int]$Number, [string]$HostName = '127.0.0.1') { return $true }
    function Start-Process {
        param([string]$FilePath, $ArgumentList, $WorkingDirectory, [switch]$PassThru)
        $script:launches += $FilePath
    }
    function Invoke-RestMethod {
        param($Uri, $TimeoutSec)
        $sha = [System.Security.Cryptography.SHA256]::Create()
        try {
            $identity = -join ($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())) | ForEach-Object { $_.ToString('x2') })
        } finally { $sha.Dispose() }
        $app = if ($script:wrongApplication) { 'other-app' } else { 'morse-typing' }
        return @{ application = $app; installation = $identity }
    }
    Start-Application $runtime
    if ($script:launches.Count -ne 1 -or $script:launches[0] -notlike 'http://127.0.0.1:8080/*') {
        throw 'Existing healthy server was not reused or unexpected software was started.'
    }
    Write-Host 'PASS: Ready server is reused and browser receives the local URL'
    $script:wrongApplication = $true
    $rejected = $false
    try { Start-Application $runtime } catch {
        if ($_.Exception.Message -notlike '*another application*') { throw }
        $rejected = $true
    }
    if (-not $rejected -or $script:launches.Count -ne 1) {
        throw 'Occupied port was not rejected before launching the browser.'
    }
    Write-Host 'PASS: Another application on the web port is rejected'
    function Get-DatabaseEndpoint($Runtime) { return @{ host = 'database.example.invalid'; port = 3307 } }
    function Test-Port([int]$Number, [string]$HostName = '127.0.0.1') { return $HostName -eq '127.0.0.1' }
    $rejected = $false
    try { Start-Application $runtime } catch {
        if ($_.Exception.Message -notlike '*database.example.invalid:3307*unreachable*') { throw }
        $rejected = $true
    }
    if (-not $rejected -or $script:launches.Count -ne 1) {
        throw 'An unavailable remote database must be reported without starting a local replacement.'
    }
    Write-Host 'PASS: Remote/custom-port database settings are respected without starting unrelated local software'
} finally {
    foreach ($file in @('php.ini', 'runtime.json')) {
        $path = Join-Path $runtimeDirectory $file
        if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path }
    }
    if (Test-Path -LiteralPath $runtimeDirectory) { Remove-Item -LiteralPath $runtimeDirectory }
}
