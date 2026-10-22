$ErrorActionPreference = 'Stop'
$envPath = Join-Path (Split-Path $PSScriptRoot -Parent) '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Khong tim thay .env cua du an.'
}
Write-Host 'Nhap MAT KHAU DANG NHAP APP TTLOCK (khong phai ma mo cua).'
Write-Host 'Ky tu se duoc an. Nhan Enter sau khi nhap.'
$securePassword = Read-Host 'Mat khau TTLock' -AsSecureString
if ($securePassword.Length -eq 0) {
    throw 'Mat khau trong; chua thay doi .env.'
}
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    $passwordBytes = [Text.Encoding]::UTF8.GetBytes($plainPassword)
    $algorithm = [Security.Cryptography.MD5]::Create()
    try {
        $hash = ([BitConverter]::ToString($algorithm.ComputeHash($passwordBytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $algorithm.Dispose()
        [Array]::Clear($passwordBytes, 0, $passwordBytes.Length)
        $plainPassword = $null
    }
    $content = [IO.File]::ReadAllText($envPath)
    if ([regex]::IsMatch($content, '(?m)^\s*TTLOCK_PASSWORD_MD5\s*=')) {
        $content = [regex]::Replace($content, '(?m)^\s*TTLOCK_PASSWORD_MD5\s*=[^\r\n]*', 'TTLOCK_PASSWORD_MD5=' + $hash)
    } else {
        $content = $content.TrimEnd() + "`nTTLOCK_PASSWORD_MD5=" + $hash + "`n"
    }
    [IO.File]::WriteAllText($envPath, $content.Replace("`r`n", "`n"), [Text.UTF8Encoding]::new($false))
    $hash = $null
    $content = $null
    Write-Host 'Da luu MD5 vao .env. Khong hien thi mat khau hoac hash.' -ForegroundColor Green
    Write-Host 'Bao lai trong chat: da nhap mat khau. Chua tao ma hay mo khoa.'
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    $securePassword.Dispose()
}
