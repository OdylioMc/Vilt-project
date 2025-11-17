<#
export-products.ps1

PowerShell exporter for MSSQL (Azure SQL) using your semicolon-style connection string.

Usage in workflow:
  pwsh -NoProfile -NoLogo -File .\export-products.ps1
Optional params:
  -OutputDir 'pjm-data'    (default)
  -MaxRows 10000           (default)
  -IncludeThumbnail        (switch, include image as base64 / data-uri)

Requires DB_CONNECTION_STRING secret set in the repository (your semicolon style string).
#>

param(
  [string]$OutputDir = 'pjm-data',
  [int]$MaxRows = 10000,
  [switch]$IncludeThumbnail
)

# Read connection string from environment
$cs = $env:DB_CONNECTION_STRING
if (-not $cs -or $cs.Trim() -eq '') {
  Write-Error "DB_CONNECTION_STRING environment variable is not set. Add it to repository secrets."
  exit 1
}

Write-Host "Starting export-products.ps1 (IncludeThumbnail = $($IncludeThumbnail.IsPresent))"

# Choose query depending on whether thumbnails are requested
if ($IncludeThumbnail.IsPresent) {
  $query = @"
SELECT TOP ($MaxRows)
  [ProductId],
  [CategoryId],
  [SKU],
  [Name],
  [Description],
  [Price],
  [IsActive],
  [CreatedAt],
  [Thumbnail],
  [ThumbnailContentType],
  [ThumbnailUpdatedAt]
FROM [catalog].[Products]
ORDER BY [ProductId];
"@
} else {
  $query = @"
SELECT TOP ($MaxRows)
  [ProductId],
  [CategoryId],
  [SKU],
  [Name],
  [Description],
  [Price],
  [IsActive],
  [CreatedAt],
  [ThumbnailContentType],
  [ThumbnailUpdatedAt]
FROM [catalog].[Products]
ORDER BY [ProductId];
"@
}

try {
  Add-Type -AssemblyName System.Data
  $conn = New-Object System.Data.SqlClient.SqlConnection $cs
  $cmd = $conn.CreateCommand()
  $cmd.CommandText = $query
  $conn.Open()
  $reader = $cmd.ExecuteReader()
} catch {
  Write-Error "Failed to connect or execute query: $($_.Exception.Message)"
  exit 1
}

$dt = New-Object System.Data.DataTable
try {
  $dt.Load($reader) | Out-Null
  $reader.Close()
  $conn.Close()
} catch {
  Write-Error "Error reading results: $($_.Exception.Message)"
  exit 1
}

$rows = New-Object System.Collections.ArrayList

foreach ($r in $dt.Rows) {
  $obj = @{}
  foreach ($c in $dt.Columns) {
    $name = $c.ColumnName
    $val = $r[$name]

    # Convert DB NULL to $null
    if ($val -eq [System.DBNull]::Value) {
      $obj[$name] = $null
      continue
    }

    # If thumbnail column present and is byte[] -> handle below (skip raw bytes for JSON)
    if ($name -eq 'Thumbnail' -and $val -is [byte[]]) {
      # store temporarily as byte[]; we'll convert below after we have content-type
      $obj['_ThumbnailBytes'] = $val
      continue
    }

    # Default assignment
    $obj[$name] = $val
  }

  # If we captured thumbnail bytes convert to base64 and optionally data-uri
  if ($obj.ContainsKey('_ThumbnailBytes')) {
    $bytes = $obj['_ThumbnailBytes']
    $b64 = [System.Convert]::ToBase64String($bytes)
    $obj.Remove('_ThumbnailBytes') > $null
    # Add base64 field
    $obj['ThumbnailBase64'] = $b64
    # If content type present, add data-uri
    if ($obj.ContainsKey('ThumbnailContentType') -and $obj['ThumbnailContentType']) {
      $ct = $obj['ThumbnailContentType']
      $obj['ThumbnailDataUri'] = "data:$ct;base64,$b64"
    }
    # Optionally remove raw Thumbnail column if present (we replaced it)
    if ($obj.ContainsKey('Thumbnail')) { $obj.Remove('Thumbnail') > $null }
  }

  $rows.Add((New-Object PSObject -Property $obj)) | Out-Null
}

# Ensure output dir exists
if (-not (Test-Path -Path $OutputDir)) {
  New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

$outFile = Join-Path $OutputDir 'products.json'

try {
  # Convert to JSON pretty with sufficient depth
  $json = $rows | ConvertTo-Json -Depth 6
  # Write file as UTF8 (no BOM)
  [System.IO.File]::WriteAllText($outFile, $json, [System.Text.Encoding]::UTF8)
} catch {
  Write-Error "Failed to write JSON: $($_.Exception.Message)"
  exit 1
}

Write-Host "Wrote $($rows.Count) products to $outFile"
exit 0
