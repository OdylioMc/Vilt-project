<#
Export-products.ps1 (modified)

- Reads DB_CONNECTION_STRING from env (Azure SQL).
- Exports products to pjm-data/products.json and pjm-data/products.csv.
- Writes thumbnails as files to pjm-data/images/<ProductId>.<ext>.
- Adds ThumbnailPath = "images/<file>" in the JSON objects (relative to pjm-data).
- Detects optional source columns: Afmeting, Kleur, Vorm, ParentProductId, ParentId.
- Sets group_id in CSV as:
    - ParentId (if present), otherwise
    - Name (default grouping on Name as requested).
- Usage:
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

# Helper: get list of columns in the source table so we can optionally include Afmeting/Kleur/Vorm/ParentId
Add-Type -AssemblyName System.Data
try {
  $metaConn = New-Object System.Data.SqlClient.SqlConnection $cs
  $metaConn.Open()
  $metaCmd = $metaConn.CreateCommand()
  $metaCmd.CommandText = "
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'catalog' AND TABLE_NAME = 'Products';
  "
  $metaReader = $metaCmd.ExecuteReader()
  $sourceCols = New-Object System.Collections.Generic.List[string]
  while ($metaReader.Read()) {
    $sourceCols.Add($metaReader.GetString(0))
  }
  $metaReader.Close()
  $metaConn.Close()
} catch {
  Write-Warning "Could not read INFORMATION_SCHEMA.COLUMNS: $($_.Exception.Message)"
  # fall back to safe default set (will fail later if columns truly missing)
  $sourceCols = @('ProductId','CategoryId','SKU','Name','Description','Price','IsActive','CreatedAt','Thumbnail','ThumbnailContentType','ThumbnailUpdatedAt','Afmeting','Kleur','Vorm','ParentProductId','ParentId')
}

# Decide whether optional columns exist
$hasAfmeting = $sourceCols -contains 'Afmeting'
$hasKleur = $sourceCols -contains 'Kleur'
$hasVorm = $sourceCols -contains 'Vorm'
$hasParentProductId = $sourceCols -contains 'ParentProductId'
$hasParentId = $sourceCols -contains 'ParentId'

# Build the SELECT column list dynamically so we only request columns that exist
$baseCols = @(
  '[ProductId]',
  '[CategoryId]',
  '[SKU]',
  '[Name]',
  '[Description]',
  '[Price]',
  '[IsActive]',
  '[CreatedAt]',
  '[Thumbnail]',
  '[ThumbnailContentType]',
  '[ThumbnailUpdatedAt]'
)

if ($hasAfmeting) { $baseCols += '[Afmeting]' }
if ($hasKleur)    { $baseCols += '[Kleur]' }
if ($hasVorm)     { $baseCols += '[Vorm]' }

# Build ParentId expression: use ParentProductId if present, else ParentId if present, else NULL as ParentId
if ($hasParentProductId) {
  $parentExpr = '[ParentProductId] AS ParentId'
} elseif ($hasParentId) {
  $parentExpr = '[ParentId] AS ParentId'
} else {
  $parentExpr = 'NULL AS ParentId'
}

$selectList = ($baseCols -join ",`n  ") + ",`n  " + $parentExpr

$query = @"
SELECT TOP ($MaxRows)
  $selectList
FROM [catalog].[Products]
ORDER BY [ProductId];
"@

try {
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
$csvRows = @()

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
      $ct = $null
      if ($dt.Columns.Contains('ThumbnailContentType')) { $ct = $r['ThumbnailContentType'] }
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

  # Ensure the JSON contains keys the importer expects:
  foreach ($k in @('ProductId','ParentId','SKU','Name','Price','Afmeting','Kleur','Vorm','ThumbnailPath','ThumbnailContentType')) {
    if (-not $obj.ContainsKey($k)) { $obj[$k] = $null }
  }

  # Convert Price to string with dot decimal to make importer parsing consistent
  if ($obj['Price'] -ne $null) {
    try {
      $num = [decimal]$obj['Price']
      $obj['Price'] = $num.ToString("0.00", [System.Globalization.CultureInfo]::InvariantCulture)
    } catch {
      # leave as-is if conversion fails
    }
  }

  $rows += $obj

  # Also prepare CSV row for easier variable-product import (sku,name,price,afmeting,kleur,vorm,group_id)
  $group_id = $obj['ParentId']
  if (-not $group_id -or $group_id -eq $null -or $group_id -eq '') {
    # default to Name grouping (option C)
    $group_id = $obj['Name']
  }
  $csvObj = [PSCustomObject]@{
    sku = ($obj['SKU'] -ne $null) ? $obj['SKU'] : ''
    name = ($obj['Name'] -ne $null) ? $obj['Name'] : ''
    price = ($obj['Price'] -ne $null) ? $obj['Price'] : ''
    afmeting = ($obj['Afmeting'] -ne $null) ? $obj['Afmeting'] : ''
    kleur = ($obj['Kleur'] -ne $null) ? $obj['Kleur'] : ''
    vorm = ($obj['Vorm'] -ne $null) ? $obj['Vorm'] : ''
    group_id = $group_id
    ThumbnailPath = ($obj['ThumbnailPath'] -ne $null) ? $obj['ThumbnailPath'] : ''
  }
  $csvRows += $csvObj
}

# Write JSON to file (pretty printed)
$outFileJson = Join-Path $OutputDir 'products.json'
try {
  $json = $rows | ConvertTo-Json -Depth 6
  [System.IO.File]::WriteAllText($outFileJson, $json, [System.Text.Encoding]::UTF8)
  Write-Host "Wrote $($rows.Count) products to $outFileJson"
} catch {
  Write-Error "Failed to write JSON: $($_.Exception.Message)"
  exit 1
}

# Write CSV to file for the variable product import script (comma separated, UTF8)
$outFileCsv = Join-Path $OutputDir 'products.csv'
try {
  if ($csvRows.Count -gt 0) {
    $csvRows | Export-Csv -Path $outFileCsv -NoTypeInformation -Encoding UTF8
    Write-Host "Wrote $($csvRows.Count) rows to $outFileCsv"
  } else {
    Write-Host "No CSV rows to write."
  }
} catch {
  Write-Error "Failed to write CSV: $($_.Exception.Message)"
  exit 1
}

exit 0
