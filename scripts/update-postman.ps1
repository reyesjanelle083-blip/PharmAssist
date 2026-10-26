$ErrorActionPreference = "Stop"

$collectionPath = "tests\postman\pharmassist.postman_collection.json"
$environmentPath = "tests\postman\local.postman_environment.json"

$collection = Get-Content $collectionPath -Raw | ConvertFrom-Json
$environment = Get-Content $environmentPath -Raw | ConvertFrom-Json

# ---------------------------------------------------------------------------
# Backups go to TEMP so they do not become untracked repository files.
# ---------------------------------------------------------------------------

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"

Copy-Item `
    $collectionPath `
    (Join-Path $env:TEMP "pharmassist-postman-$stamp.json")

Copy-Item `
    $environmentPath `
    (Join-Path $env:TEMP "pharmassist-postman-env-$stamp.json")


# ---------------------------------------------------------------------------
# Environment helpers
# ---------------------------------------------------------------------------

function Ensure-EnvironmentVariable {
    param(
        [string]$Key,
        [string]$Value = ""
    )

    $existing = @(
        $environment.values |
        Where-Object { $_.key -eq $Key }
    )

    if ($existing.Count -eq 0) {
        $environment.values = @($environment.values) + @(
            [PSCustomObject][ordered]@{
                key     = $Key
                value   = $Value
                enabled = $true
            }
        )
    }
}

Ensure-EnvironmentVariable "ceo_access_token"
Ensure-EnvironmentVariable "email_manual_test"
Ensure-EnvironmentVariable "verification_code"
Ensure-EnvironmentVariable "reset_code"
Ensure-EnvironmentVariable "reset_password_new" "NewPassword1234"


# ---------------------------------------------------------------------------
# Collection helpers
# ---------------------------------------------------------------------------

function Find-Request {
    param(
        $Items,
        [string]$Name
    )

    foreach ($item in $Items) {
        if ($item.name -eq $Name -and $item.request) {
            return $item
        }

        if ($item.item) {
            $found = Find-Request $item.item $Name

            if ($found) {
                return $found
            }
        }
    }

    return $null
}

function New-TestEvent {
    param([string[]]$Lines)

    return [PSCustomObject][ordered]@{
        listen = "test"
        script = [PSCustomObject][ordered]@{
            type = "text/javascript"
            exec = $Lines
        }
    }
}

function New-PreRequestEvent {
    param([string[]]$Lines)

    return [PSCustomObject][ordered]@{
        listen = "prerequest"
        script = [PSCustomObject][ordered]@{
            type = "text/javascript"
            exec = $Lines
        }
    }
}

function New-Url {
    param([string[]]$Path)

    return [PSCustomObject][ordered]@{
        raw  = "{{base_url}}/" + ($Path -join "/")
        host = @("{{base_url}}")
        path = $Path
    }
}

function New-JsonPost {
    param(
        [string]$Name,
        [string[]]$Path,
        [string]$RawBody,
        [string[]]$Tests,
        [string[]]$PreRequest = @()
    )

    $events = @()

    if ($PreRequest.Count -gt 0) {
        $events += New-PreRequestEvent $PreRequest
    }

    $events += New-TestEvent $Tests

    return [PSCustomObject][ordered]@{
        name  = $Name
        event = $events
        request = [PSCustomObject][ordered]@{
            method = "POST"
            header = @(
                [PSCustomObject][ordered]@{
                    key   = "Content-Type"
                    value = "application/json"
                },
                [PSCustomObject][ordered]@{
                    key   = "Accept"
                    value = "application/json"
                }
            )
            body = [PSCustomObject][ordered]@{
                mode = "raw"
                raw  = $RawBody
                options = [PSCustomObject][ordered]@{
                    raw = [PSCustomObject][ordered]@{
                        language = "json"
                    }
                }
            }
            url = New-Url $Path
        }
    }
}

function New-OcrRequest {
    param(
        [string]$Name,
        [string[]]$Path,
        [string]$Fixture,
        [string]$Token,
        [string[]]$Tests
    )

    $headers = @(
        [PSCustomObject][ordered]@{
            key   = "Accept"
            value = "application/json"
        }
    )

    if ($Token) {
        $headers += [PSCustomObject][ordered]@{
            key   = "Authorization"
            value = "Bearer $Token"
            type  = "text"
        }
    }

    return [PSCustomObject][ordered]@{
        name  = $Name
        event = @(
            New-TestEvent $Tests
        )
        request = [PSCustomObject][ordered]@{
            method = "POST"
            header = $headers
            body = [PSCustomObject][ordered]@{
                mode = "formdata"
                formdata = @(
                    [PSCustomObject][ordered]@{
                        key  = "image"
                        type = "file"
                        src  = $Fixture
                    }
                )
            }
            url = New-Url $Path
        }
    }
}


# ---------------------------------------------------------------------------
# Shared JavaScript assertions
# ---------------------------------------------------------------------------

$successEnvelope = @(
    "var body = pm.response.json();",
    "pm.test('envelope has all six keys', function () {",
    "  ['status','service','request_id','data','error','warnings'].forEach(function (k) {",
    "    pm.expect(body).to.have.property(k);",
    "  });",
    "});",
    "pm.test('request_id is present', function () {",
    "  pm.expect(body.request_id).to.be.a('string').and.not.empty;",
    "});"
)

$errorEnvelope = @(
    "var body = pm.response.json();",
    "pm.test('error envelope has all six keys', function () {",
    "  ['status','service','request_id','data','error','warnings'].forEach(function (k) {",
    "    pm.expect(body).to.have.property(k);",
    "  });",
    "});"
)


# ---------------------------------------------------------------------------
# Preserve CEO JWT for the OCR role-negative test
# ---------------------------------------------------------------------------

$ceoLogin = Find-Request `
    $collection.item `
    "T03b Login CEO (role ceo, branch none)"

if ($ceoLogin) {
    $testEvent = @(
        $ceoLogin.event |
        Where-Object { $_.listen -eq "test" }
    ) | Select-Object -First 1

    if ($testEvent) {
        $alreadyAdded =
            ($testEvent.script.exec -join "`n") -match "ceo_access_token"

        if (-not $alreadyAdded) {
            $testEvent.script.exec = @($testEvent.script.exec) + @(
                "var ceoTokenBody = pm.response.json();",
                "if (pm.response.code === 200 && ceoTokenBody.data && ceoTokenBody.data.access_token) {",
                "  pm.environment.set('ceo_access_token', ceoTokenBody.data.access_token);",
                "}"
            )
        }
    }
}


# ---------------------------------------------------------------------------
# Auth recovery / verification
# ---------------------------------------------------------------------------

$manualVerifyPre = @(
    "if (!pm.environment.get('email_manual_test') || !pm.environment.get('verification_code')) {",
    "  console.warn('Set email_manual_test and verification_code before running this request.');",
    "  pm.execution.skipRequest();",
    "}"
)

$manualResetPre = @(
    "if (!pm.environment.get('email_manual_test') || !pm.environment.get('reset_code')) {",
    "  console.warn('Set email_manual_test and reset_code before running this request.');",
    "  pm.execution.skipRequest();",
    "}"
)

$verifyTests = @(
    "pm.test('status is 200', function () { pm.response.to.have.status(200); });"
) + $successEnvelope + @(
    "pm.test('data is null', function () { pm.expect(body.data).to.eql(null); });",
    "pm.test('error is null', function () { pm.expect(body.error).to.eql(null); });"
)

$forgotTests = @(
    "pm.test('status is 200', function () { pm.response.to.have.status(200); });"
) + $successEnvelope + @(
    "pm.test('response does not disclose account existence', function () {",
    "  pm.expect(body.data).to.eql(null);",
    "  pm.expect(body.error).to.eql(null);",
    "});"
)

$resetTests = @(
    "pm.test('status is 200', function () { pm.response.to.have.status(200); });"
) + $successEnvelope + @(
    "pm.test('data is null after reset', function () { pm.expect(body.data).to.eql(null); });"
)

$authRecoveryFolder = [PSCustomObject][ordered]@{
    name = "03 Auth Recovery"
    item = @(
        (New-JsonPost `
            "T32 MANUAL Verify email with 6-digit code" `
            @("auth", "verify-email") `
            @'
{
  "email": "{{email_manual_test}}",
  "code": "{{verification_code}}"
}
'@ `
            $verifyTests `
            $manualVerifyPre),

        (New-JsonPost `
            "T33 Forgot password - existing account" `
            @("auth", "forgot-password") `
            @'
{
  "email": "{{email_customer_approved}}"
}
'@ `
            $forgotTests),

        (New-JsonPost `
            "T34 Forgot password - unknown account returns same public response" `
            @("auth", "forgot-password") `
            @'
{
  "email": "unknown.password.reset@example.invalid"
}
'@ `
            $forgotTests),

        (New-JsonPost `
            "T35 MANUAL Reset password with emailed 6-digit code" `
            @("auth", "reset-password") `
            @'
{
  "email": "{{email_manual_test}}",
  "code": "{{reset_code}}",
  "password": "{{reset_password_new}}",
  "password_confirmation": "{{reset_password_new}}"
}
'@ `
            $resetTests `
            $manualResetPre)
    )
}


# ---------------------------------------------------------------------------
# OCR
# ---------------------------------------------------------------------------

$prescriptionTests = @(
    "pm.test('status is 200', function () { pm.response.to.have.status(200); });"
) + $successEnvelope + @(
    "pm.test('prescription OCR contract is present', function () {",
    "  pm.expect(body.data).to.have.property('ocr_text');",
    "  pm.expect(body.data).to.have.property('confidence');",
    "  pm.expect(body.data).to.have.property('ocr_status');",
    "  pm.expect(body.data).to.have.property('source');",
    "});",
    "pm.test('OCR status is valid', function () {",
    "  pm.expect(['success','low_confidence','unavailable']).to.include(body.data.ocr_status);",
    "});"
)

$labelTests = @(
    "pm.test('status is 200', function () { pm.response.to.have.status(200); });"
) + $successEnvelope + @(
    "pm.test('label OCR structured fields are present', function () {",
    "  ['medicine_name','dosage','batch_no','expiration_date','ocr_text','confidence','ocr_status','source'].forEach(function (k) {",
    "    pm.expect(body.data).to.have.property(k);",
    "  });",
    "});",
    "pm.test('OCR status is valid', function () {",
    "  pm.expect(['success','low_confidence','unavailable']).to.include(body.data.ocr_status);",
    "});"
)

$unauthorizedTests = @(
    "pm.test('status is 401', function () { pm.response.to.have.status(401); });"
) + $errorEnvelope

$forbiddenTests = @(
    "pm.test('status is 403', function () { pm.response.to.have.status(403); });"
) + $errorEnvelope

$ocrFolder = [PSCustomObject][ordered]@{
    name = "04 OCR"
    item = @(
        (New-OcrRequest `
            "T36 Prescription OCR - authenticated customer" `
            @("prescriptions", "ocr") `
            "tests/fixtures/prescription-sample.png" `
            "{{access_token}}" `
            $prescriptionTests),

        (New-OcrRequest `
            "T37 Medicine Label OCR - authenticated customer" `
            @("labels", "ocr") `
            "tests/fixtures/medicine-label-sample.png" `
            "{{access_token}}" `
            $labelTests),

        (New-OcrRequest `
            "T38 Medicine Label OCR - no token returns 401" `
            @("labels", "ocr") `
            "tests/fixtures/medicine-label-sample.png" `
            "" `
            $unauthorizedTests),

        (New-OcrRequest `
            "T39 Medicine Label OCR - CEO role returns 403" `
            @("labels", "ocr") `
            "tests/fixtures/medicine-label-sample.png" `
            "{{ceo_access_token}}" `
            $forbiddenTests)
    )
}


# ---------------------------------------------------------------------------
# Upsert folders before the 99 Rate Limit folder
# ---------------------------------------------------------------------------

$existing = @(
    $collection.item |
    Where-Object {
        $_.name -ne "03 Auth Recovery" -and
        $_.name -ne "04 OCR"
    }
)

$normalFolders = @(
    $existing |
    Where-Object { $_.name -notlike "99 *" }
)

$rateFolders = @(
    $existing |
    Where-Object { $_.name -like "99 *" }
)

$collection.item = @(
    $normalFolders +
    @($authRecoveryFolder, $ocrFolder) +
    $rateFolders
)


# ---------------------------------------------------------------------------
# Write UTF-8 without BOM
# ---------------------------------------------------------------------------

$utf8 = New-Object System.Text.UTF8Encoding($false)

$collectionJson = $collection | ConvertTo-Json -Depth 100
$environmentJson = $environment | ConvertTo-Json -Depth 100

[System.IO.File]::WriteAllText(
    (Resolve-Path $collectionPath),
    $collectionJson,
    $utf8
)

[System.IO.File]::WriteAllText(
    (Resolve-Path $environmentPath),
    $environmentJson,
    $utf8
)

Write-Host ""
Write-Host "Postman collection updated successfully." -ForegroundColor Green
Write-Host "Added: 03 Auth Recovery"
Write-Host "Added: 04 OCR"
Write-Host "Added required environment variables."