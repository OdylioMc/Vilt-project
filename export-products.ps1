param(
  [string]$OutputDir = 'pjm-data',
  [string]$ColumnsConfig = './columns.json'
)

Write-Host "Starting export-products.ps1"
Write-Host "OutputDir: ${OutputDir}"
Write-Host "ColumnsConfig: ${ColumnsConfig}"

# Optioneel: toon of DB_CONNECTION_STRING aanwezig is (NIET de waarde loggen in productie)
if ($env:DB_CONNECTION_STRING) {
  Write-Host "DB_CONNECTION_STRING is set (using it if your real script supports DB export)"
} else {
  Write-Host "DB_CONNECTION_STRING is NOT set - generating sample data instead"
}

# Zorg dat outputmap bestaat
if (-not (Test-Path -Path $OutputDir)) {
  New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

# Voorbeeld: schrijf een sample products.json zodat Actions iets kan uploaden
$products = @(
  @{ id = 1; name = 'Voorbeeld product A'; price = 9.99 },
  @{ id = 2; name = 'Voorbeeld product B'; price = 19.9 }
)
$products | ConvertTo-Json -Depth 5 | Out-File -FilePath (Join-Path $OutputDir 'products.json') -Encoding utf8

# Kopieer eventueel columns-config als die bestaat (optioneel)
if (Test-Path -Path $ColumnsConfig) {
  Copy-Item -Path $ColumnsConfig -Destination (Join-Path $OutputDir (Split-Path $ColumnsConfig -Leaf)) -Force
}

Write-Host "Export complete. Files in ${OutputDir}:"
Get-ChildItem -Path $OutputDir -Recurse | ForEach-Object { Write-Host "  " $_.FullName }

# Succes exitcode
exit 0
