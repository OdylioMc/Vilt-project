<#
Export-products.ps1

- Leest jouw semicolon-style DB_CONNECTION_STRING uit env (Azure SQL).
- Exporteert products naar pjm-data/products.json.
- Schrijft thumbnails als losse bestanden in pjm-data/images/<ProductId>.<ext>.
- Voegt in de JSON voor elk product ThumbnailPath = "images/<file>" (relatief tov pjm-data).
- Usage in workflow / locally:
    pwsh -NoProfile -NoLogo -File .\export-products.ps1 -OutputDir 'pjm-data' -MaxRows 10000
#>

param(
  [string]$OutputDir = 'pjm-data',
  [int]$MaxRows = 10000
)

$cs = $env:DB_CONNECTION_STRING
if (-not $cs -or $cs.Trim() -eq '') {
  Write-Error "DB_CONNECTION_STRING environment variable is not set."
  exit 1
}

Write-Host "Exporting up to $MaxRows products to '$OutputDir'..."

# Prepare output dirs
if (-not (Test-Path -Path $OutputDir)) {
  New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}
$imagesDir = Join-Path $OutputDir 'images'
if (-not (Test-Path -Path $imagesDir)) {
  New-Item -ItemType Directory -Path $imagesDir -Force | Out-Null
}

# Query
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

$rows = @()

function Get-ExtensionFromContentType($ct) {
  switch ($ct) {
    'image/png' { return '.png' }
    'image/jpeg' { return '.jpg' }
    'image/jpg' { return '.jpg' }
    'image/webp' { return '.webp' }
    'image/gif' { return '.gif' }
    default { return '.bin' }
  }
}

foreach ($r in $dt.Rows) {
  $obj = @{}
  foreach ($c in $dt.Columns) {
    $name = $c.ColumnName
    $val = $r[$name]
    if ($val -eq [System.DBNull]::Value) {
      $obj[$name] = $null
      continue
    }

    # Save thumbnail bytes as files, and don't include raw byte[] in JSON
    if ($name -eq 'Thumbnail' -and $val -is [byte[]]) {
      $bytes = $val
      $ct = $r['ThumbnailContentType']
      $ext = Get-ExtensionFromContentType $ct
      $fname = "{0}{1}" -f $r['ProductId'], $ext
      $fullPath = Join-Path $imagesDir $fname
      try {
        [System.IO.File]::WriteAllBytes($fullPath, $bytes)
        # store relative path into JSON object
        $obj['ThumbnailPath'] = "images/$fname"
        # keep content type too
        if ($ct) { $obj['ThumbnailContentType'] = $ct }
      } catch {
        Write-Warning "Failed to write thumbnail for ProductId $($r['ProductId']): $($_.Exception.Message)"
      }
      continue
    }

    # Default assignment for other columns
    $obj[$name] = $val
  }

  $rows += $obj
}

# Write JSON to file (pretty printed)
$outFile = Join-Path $OutputDir 'products.json'
try {
  $json = $rows | ConvertTo-Json -Depth 6
  # Write UTF8 (BOM may be present on some runners; importer handles BOM)
  [System.IO.File]::WriteAllText($outFile, $json, [System.Text.Encoding]::UTF8)
  Write-Host "Wrote $($rows.Count) products to $outFile"
} catch {
  Write-Error "Failed to write JSON: $($_.Exception.Message)"
  exit 1
}

exit 0
