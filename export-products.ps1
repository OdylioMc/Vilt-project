<#
Simple PowerShell exporter for MSSQL (Azure SQL).

Usage in workflow (Windows runner):
  pwsh -NoProfile -NoLogo -File .\export-products.ps1

It reads DB_CONNECTION_STRING from env (your semicolon-style connection string),
runs a query and writes pjm-data\products.json.

Adjust $query below to match your schema (table and column names).
#>

param(
  [string]$OutputDir = 'pjm-data',
  [int]$MaxRows = 10000
)

# Read connection string from environment (set as repo secret DB_CONNECTION_STRING)
$cs = $env:DB_CONNECTION_STRING
if (-not $cs -or $cs.Trim() -eq '') {
  Write-Error "DB_CONNECTION_STRING environment variable is not set. Please add it to repository secrets."
  exit 1
}

Write-Host "Using DB connection string (masked):" ($cs.Substring(0, [Math]::Min(30, $cs.Length)) + '...')
# ====== ADAPT THIS QUERY TO YOUR DATABASE ======
# Replace table/column names with your real product table
# Example (change to your actual schema):
$query = @"
SELECT TOP ($MaxRows) id, title, price
FROM products
ORDER BY id
"@
# ===============================================

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

# Load data into DataTable
$dt = New-Object System.Data.DataTable
try {
  $dt.Load($reader) | Out-Null
  $reader.Close()
  $conn.Close()
} catch {
  Write-Error "Error reading results: $($_.Exception.Message)"
  exit 1
}

# Convert DataTable rows to array of PSObjects for nicer JSON
$rows = @()
foreach ($r in $dt.Rows) {
  $obj = @{}
  foreach ($c in $dt.Columns) {
    $name = $c.ColumnName
    $obj[$name] = $r[$name]
  }
  $rows += (New-Object PSObject -Property $obj)
}

# Ensure output dir exists
if (-not (Test-Path -Path $OutputDir)) {
  New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

$outFile = Join-Path $OutputDir 'products.json'

# Convert to JSON (pretty)
try {
  $json = $rows | ConvertTo-Json -Depth 5 -Compress:$false
  # Write with UTF8
  Set-Content -Path $outFile -Value $json -Encoding UTF8
} catch {
  Write-Error "Failed to write JSON: $($_.Exception.Message)"
  exit 1
}

Write-Host "Wrote $($rows.Count) products to $outFile"
exit 0
