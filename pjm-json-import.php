<#
.SYNOPSIS
  Exporteert productrecords naar JSON bestanden en downloadt bijbehorende afbeeldingen.
  Kolom‑namen worden ingelezen uit columns.json zodat je het script niet hoeft te wijzigen.

.USAGE
  pwsh ./export-products.ps1 -ConnectionString 'Server=tcp:...;Database=...;User Id=...;Password=...' -OutputDir "./pjm-data"

.PARAMETER ConnectionString
  SQL Server connection string (Azure SQL). In Actions gebruik liever secret DB_CONNECTION_STRING.

.PARAMETER OutputDir
  Map waar JSON en images naar geschreven worden (default ./pjm-data).

.PARAMETER ColumnsConfig
  Pad naar JSON config met kolommapping (default ./columns.json).
#>

param(
    [Parameter(Mandatory=$false)][string]$ConnectionString = $env:DB_CONNECTION_STRING,
    [string]$OutputDir = "./pjm-data",
    [string]$ColumnsConfig = "./columns.json",
    [string]$Query = ""
)

if (-not $ConnectionString) {
    Write-Error "ConnectionString is empty. Set -ConnectionString or the DB_CONNECTION_STRING environment variable."
    exit 2
}

# Read columns config
if (-not (Test-Path $ColumnsConfig)) {
    Write-Error "Columns config not found at $ColumnsConfig. Copy columns.json and edit for your schema."
    exit 3
}
$colsJson = Get-Content $ColumnsConfig -Raw | ConvertFrom-Json

# Default query if not supplied (uses column names from config)
if ([string]::IsNullOrWhiteSpace($Query)) {
    $selCols = @()
    $selCols += $colsJson.id
    $selCols += $colsJson.title
    if ($colsJson.description) { $selCols += $colsJson.description }
    if ($colsJson.price) { $selCols += $colsJson.price }
    if ($colsJson.imageUrl) { $selCols += $colsJson.imageUrl }
    $Query = "SELECT " + ($selCols -join ", ") + " FROM " + $colsJson.table + " WHERE " + ($colsJson.publishedCondition)
}

Write-Host "Using query: $Query"

# Setup output folders
$fullOut = Resolve-Path -LiteralPath $OutputDir -ErrorAction SilentlyContinue
if (-not $fullOut) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
    $fullOut = Resolve-Path -LiteralPath $OutputDir
}
$fullOutPath = $fullOut.Path
$imagesDir = Join-Path $fullOutPath "images"
if (-not (Test-Path $imagesDir)) { New-Item -ItemType Directory -Path $imagesDir -Force | Out-Null }

# Load .NET DB client
try {
    Add-Type -AssemblyName "System.Data"
} catch {
    Write-Warning "Could not load System.Data assembly directly; continuing."
}

# Open SQL connection and read rows
$connection = New-Object System.Data.SqlClient.SqlConnection $ConnectionString
try {
    $connection.Open()
} catch {
    Write-Error "Failed to open SQL connection: $_"
    exit 3
}

$command = $connection.CreateCommand()
$command.CommandText = $Query

try {
    $reader = $command.ExecuteReader()
} catch {
    Write-Error "Query failed: $_"
    $connection.Close()
    exit 4
}

# HttpClient for images
$http = New-Object System.Net.Http.HttpClient
$http.Timeout = [System.TimeSpan]::FromSeconds(30)

while ($reader.Read()) {
    # Map columns using columns.json
    $id = $null; $title = $null; $desc = $null; $price = $null; $imageUrl = $null
    try {
        $id = $reader[$colsJson.id]
    } catch { $id = $reader[0] }
    try { if ($colsJson.title) { $title = $reader[$colsJson.title] } } catch {}
    try { if ($colsJson.description) { $desc = $reader[$colsJson.description] } } catch {}
    try { if ($colsJson.price) { $price = $reader[$colsJson.price] } } catch {}
    try { if ($colsJson.imageUrl) { $imageUrl = $reader[$colsJson.imageUrl] } } catch {}

    # Build object to save as JSON (pas velden aan naar schema)
    $obj = [PSCustomObject]@{
        id = $id
        title = $title
        description = $desc
        price = $price
        image = $imageUrl
        exportedAt = (Get-Date).ToString("o")
    }

    # JSON bestandsnaam (sanitiseer id)
    $safeId = ($id -as [string]) -replace '[^0-9A-Za-z_\-]', '_'
    $jsonName = "product_$safeId.json"
    $jsonPath = Join-Path $fullOutPath $jsonName
    $obj | ConvertTo-Json -Depth 10 | Out-File -FilePath $jsonPath -Encoding UTF8

    # Download image als aanwezig
    if ($imageUrl -and $imageUrl.ToString().Trim() -ne "") {
        try {
            $uri = [System.Uri] $imageUrl
            $ext = [System.IO.Path]::GetExtension($uri.AbsolutePath)
            if ([string]::IsNullOrWhiteSpace($ext)) { $ext = ".jpg" }
            $imgName = "thumb_$safeId$ext"
            $imgPath = Join-Path $imagesDir $imgName

            $resp = $http.GetAsync($uri).Result
            if ($resp.IsSuccessStatusCode) {
                $bytes = $resp.Content.ReadAsByteArrayAsync().Result
                [System.IO.File]::WriteAllBytes($imgPath, $bytes)
                Write-Host "Downloaded image for $safeId -> $imgName"
            } else {
                Write-Warning "Could not download image for $safeId (status $($resp.StatusCode))"
            }
        } catch {
            Write-Warning "Error downloading image for $safeId : $_"
        }
    }
}

$reader.Close()
$connection.Close()
$http.Dispose()

Write-Host "Export complete. Files in: $fullOutPath"