$ErrorActionPreference = 'Stop'
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if (!(Test-Path -LiteralPath (Join-Path $repoRoot '.env'))) { throw 'Create the local .env first. See README.md.' }
$linuxRoot = (& wsl -d Ubuntu -- wslpath -a $repoRoot).Trim()
if ($LASTEXITCODE -ne 0 -or !$linuxRoot.StartsWith('/mnt/')) { throw 'Could not resolve the repository in Ubuntu WSL.' }
if ($linuxRoot.Contains("'")) { throw 'Repository paths containing a single quote are unsupported by this launcher.' }
Write-Host 'TechPoint local preview: http://localhost:8080. Keep this terminal open; Ctrl+C stops the preview.'
& wsl -d Ubuntu -u root -- bash -lc "cd '$linuxRoot' && docker compose --env-file .env -f infra/compose.dev.yaml up app"
exit $LASTEXITCODE
