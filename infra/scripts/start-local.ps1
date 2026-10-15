$ErrorActionPreference = 'Stop'
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if (!(Test-Path -LiteralPath (Join-Path $repoRoot '.env'))) { throw 'Create the local .env first. See README.md.' }
& wsl.exe -d Ubuntu -u root --cd $repoRoot --exec test -f infra/compose.dev.yaml
if ($LASTEXITCODE -ne 0) { throw 'Could not access the repository in Ubuntu WSL. Check that Ubuntu is installed and this folder is available to WSL.' }
Write-Host 'TechPoint local preview: http://localhost:8080. Keep this terminal open; Ctrl+C stops the preview.'
& wsl.exe -d Ubuntu -u root --cd $repoRoot --exec docker compose --env-file .env -f infra/compose.dev.yaml up app
exit $LASTEXITCODE
