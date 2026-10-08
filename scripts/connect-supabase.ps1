# Connects the Qistas web app to your Supabase database. Run it on YOUR computer, in a terminal:
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File scripts\connect-supabase.ps1
#
# It asks for the connection string and the database password, checks that the connection works, creates the
# Qistas tables and locks Supabase's public API out of them, and then walks you through putting the two secrets
# into Vercel through your clipboard. Nothing is sent anywhere except to your own database, and the password is
# never printed or saved to a file.
#
# Parameters exist so the script can be tested without typing: -ConnectionString, -Password, -SslMode, -Yes, -NoClipboard.

param(
    [string] $ConnectionString,
    [System.Security.SecureString] $Password,
    [string] $SslMode = 'require',
    [switch] $Yes,
    [switch] $NoClipboard
)

$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$web = Join-Path $repo 'web'
$vercelSettings = 'https://vercel.com/spring-c6fc/qistas/settings/environment-variables'

function Say($text) { Write-Host $text }
function Heading($text) { Write-Host ''; Write-Host ('== ' + $text) -ForegroundColor Cyan }
function Fail($text) { Write-Host ''; Write-Host ('STOP: ' + $text) -ForegroundColor Red; exit 1 }
function Ask($question) {
    if ($Yes) { return $true }
    $answer = Read-Host ($question + ' (y/N)')
    return $answer -match '^(y|yes)$'
}

Heading 'Qistas -> Supabase'

if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Fail 'PHP was not found. Install PHP 8.4 and open a new terminal.' }
if (-not (Test-Path (Join-Path $web 'vendor\autoload.php'))) { Fail 'Run "composer install" inside the web folder first.' }

# ---------------------------------------------------------------- 1. the connection string
if (-not $ConnectionString) {
    Say ''
    Say 'In Supabase open your project, press Connect, choose "Transaction pooler" and copy the string.'
    Say 'Paste it exactly as shown, with [YOUR-PASSWORD] still in it.'
    $ConnectionString = Read-Host 'Connection string'
}
$ConnectionString = $ConnectionString.Trim().Trim('"').Trim("'")
if ($ConnectionString -notmatch '^postgres(ql)?://') { Fail 'That does not look like a connection string (it should start with postgresql://).' }

if ($ConnectionString -match '\[YOUR-PASSWORD\]') {
    if (-not $Password) { $Password = Read-Host 'Database password (typing is hidden)' -AsSecureString }
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Password)
    try { $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    if (-not $plain) { Fail 'The password is empty.' }
    $databaseUrl = $ConnectionString.Replace('[YOUR-PASSWORD]', [Uri]::EscapeDataString($plain))
    $plain = $null
} else {
    $databaseUrl = $ConnectionString
}

try { $uri = [Uri] $databaseUrl } catch { Fail 'The connection string could not be read. If your password has special characters, let the script add it (leave [YOUR-PASSWORD] in the string).' }
if ($uri.Host -match '^db\.[a-z0-9]+\.supabase\.co$') {
    Say ''
    Say 'Note: this is the "direct connection", which only works over IPv6 and not from Vercel.' -ForegroundColor Yellow
    Say 'In Supabase press Connect again and choose "Transaction pooler" (the host ends in pooler.supabase.com).'
    if (-not (Ask 'Continue anyway?')) { exit 1 }
}

# ---------------------------------------------------------------- 2. check, set up, check
$env:DB_CONNECTION = 'pgsql'
$env:DB_URL = $databaseUrl
$env:DB_SSLMODE = $SslMode
$env:DB_EMULATE_PREPARES = 'true'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'

Push-Location $web
try {
    Heading 'Checking the connection'
    php artisan qistas:db-check --no-ansi
    if ($LASTEXITCODE -ne 0) { Fail 'The connection did not work. Read the message above, fix the string or password and run the script again.' }

    Heading 'Creating the Qistas tables'
    Say 'This adds the Qistas tables to that database, turns on row-level security for every table and removes'
    Say 'Supabase API access to them. It does not delete anything. It is safe to run again.'
    if (-not (Ask 'Continue?')) { Say 'Nothing was changed.'; exit 0 }
    php artisan qistas:setup --no-interaction --no-ansi
    if ($LASTEXITCODE -ne 0) { Fail 'Setting up the tables failed (see above). Nothing half-finished is left behind; run the script again after fixing the cause.' }

    Heading 'Checking again'
    php artisan qistas:db-check --no-ansi
    if ($LASTEXITCODE -ne 0) { Fail 'The check failed after setup.' }

    $appKey = $null
    if (-not $NoClipboard) {
        Say ''
        if (Ask 'Have you ALREADY saved an APP_KEY in Vercel for this project (so a new one would break existing data)?') {
            Say 'Keeping your existing APP_KEY.'
        } else {
            $appKey = (php artisan key:generate --show --no-ansi).Trim()
        }
    }
} finally {
    Pop-Location
}

# ---------------------------------------------------------------- 3. the two secrets into Vercel, through the clipboard
if ($NoClipboard) { Heading 'Done (clipboard step skipped)'; exit 0 }

Heading 'Put the secrets into Vercel'
Say ('Vercel page: ' + $vercelSettings)
Say 'On that page press "Add New", choose Production, and add the variables below one at a time.'
try { Start-Process $vercelSettings } catch { }

if ($appKey) {
    Say ''
    Say '1) APP_KEY: it is on your clipboard now. Name: APP_KEY   Value: paste. Save.'
    Say '   Keep a private copy somewhere safe: if it is ever lost, encrypted customer data cannot be read again.'
    Set-Clipboard -Value $appKey
    [void](Read-Host 'Press Enter when APP_KEY is saved in Vercel')
}

Say ''
Say '2) DB_URL: it is on your clipboard now. Name: DB_URL   Value: paste. Save.'
Set-Clipboard -Value $databaseUrl
[void](Read-Host 'Press Enter when DB_URL is saved in Vercel')

Set-Clipboard -Value ' '
$appKey = $null
$databaseUrl = $null
$env:DB_URL = $null

Heading 'Last step'
Say 'In Vercel open Deployments, press the three dots on the latest one, choose Redeploy.'
Say 'When it finishes, https://qistas-puce.vercel.app/ no longer shows the "Demo" banner: it is using your database.'
Say 'The clipboard has been cleared.'
