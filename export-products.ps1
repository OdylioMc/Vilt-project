<#
(Deze versie is dezelfde als eerder maar voegt Description expliciet toe aan de CSV‑export.)
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

if (-not (Test-Path -Path $OutputDir)) { New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null }
$imagesDir = Join-Path $OutputDir 'images'
if (-not (Test-Path -Path $imagesDir)) { New-Item -ItemType Directory -Path $imagesDir -Force | Out-Null }

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
  while ($metaReader.Read()) { $sourceCols.Add($metaReader.GetString(0)) }
  $metaReader.Close()
  $metaConn.Close()
} catch {
  Write-Warning "Could not read INFORMATION_SCHEMA.COLUMNS: $($_.Exception.Message)"
  $sourceCols = @('ProductId','CategoryId','SKU','Name','Description','Price','IsActive','CreatedAt','Thumbnail','ThumbnailContentType','ThumbnailUpdatedAt','Afmeting','Kleur','Vorm','ParentProductId','ParentId')
}

$hasAfmeting = $sourceCols -contains 'Afmeting'
$hasKleur = $sourceCols -contains 'Kleur'
$hasVorm = $sourceCols -contains 'Vorm'
$hasParentProductId = $sourceCols -contains 'ParentProductId'
$hasParentId = $sourceCols -contains 'ParentId'
$hasDescription = $sourceCols -contains 'Description'

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

if ($hasParentProductId) { $parentExpr = '[ParentProductId] AS ParentId' } elseif ($hasParentId) { $parentExpr = '[ParentId] AS ParentId' } else { $parentExpr = 'NULL AS ParentId' }

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
    if ($val -eq [System.DBNull]::Value) { $obj[$name] = $null; continue }

    if ($name -eq 'Thumbnail' -and $val -is [byte[]]) {
      $bytes = $val
      $ct = $null
      if ($dt.Columns.Contains('ThumbnailContentType')) { $ct = $r['ThumbnailContentType'] }
      $ext = Get-ExtensionFromContentType $ct
      $fname = "{0}{1}" -f $r['ProductId'], $ext
      $fullPath = Join-Path $imagesDir $fname
      try {
        [System.IO.File]::WriteAllBytes($fullPath, $bytes)
        $obj['ThumbnailPath'] = "images/$fname"
        if ($ct) { $obj['ThumbnailContentType'] = $ct }
      } catch {
        Write-Warning "Failed to write thumbnail for ProductId $($r['ProductId']): $($_.Exception.Message)"
      }
      continue
    }
    $obj[$name] = $val
  }

  foreach ($k in @('ProductId','ParentId','SKU','Name','Price','Afmeting','Kleur','Vorm','ThumbnailPath','ThumbnailContentType','Description')) {
    if (-not $obj.ContainsKey($k)) { $obj[$k] = $null }
  }

  if ($obj['Price'] -ne $null) {
    try {
      $num = [decimal]$obj['Price']
      $obj['Price'] = $num.ToString("0.00", [System.Globalization.CultureInfo]::InvariantCulture)
    } catch { }
  }

  $rows += $obj

  $group_id = $obj['ParentId']
  if (-not $group_id -or $group_id -eq $null -or $group_id -eq '') { $group_id = $obj['Name'] }

  $csvObj = [PSCustomObject]@{
    sku = ($obj['SKU'] -ne $null) ? $obj['SKU'] : ''
    name = ($obj['Name'] -ne $null) ? $obj['Name'] : ''
    price = ($obj['Price'] -ne $null) ? $obj['Price'] : ''
    afmeting = ($obj['Afmeting'] -ne $null) ? $obj['Afmeting'] : ''
    kleur = ($obj['Kleur'] -ne $null) ? $obj['Kleur'] : ''
    vorm = ($obj['Vorm'] -ne $null) ? $obj['Vorm'] : ''
    group_id = $group_id
    ThumbnailPath = ($obj['ThumbnailPath'] -ne $null) ? $obj['ThumbnailPath'] : ''
    Description = ($obj['Description'] -ne $null) ? $obj['Description'] : ''
  }
  $csvRows += $csvObj
}

$outFileJson = Join-Path $OutputDir 'products.json'
try {
  $json = $rows | ConvertTo-Json -Depth 6
  [System.IO.File]::WriteAllText($outFileJson, $json, [System.Text.Encoding]::UTF8)
  Write-Host "Wrote $($rows.Count) products to $outFileJson"
} catch {
  Write-Error "Failed to write JSON: $($_.Exception.Message)"
  exit 1
}

$outFileCsv = Join-Path $OutputDir 'products.csv'
try {
  if ($csvRows.Count -gt 0) {
    $csvRows | Export-Csv -Path $outFileCsv -NoTypeInformation -Encoding UTF8
    Write-Host "Wrote $($csvRows.Count) rows to $outFileCsv"
  } else { Write-Host "No CSV rows to write." }
} catch {
  Write-Error "Failed to write CSV: $($_.Exception.Message)"
  exit 1
}

exit 0
