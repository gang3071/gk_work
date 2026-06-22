$password = ConvertTo-SecureString "gang3071" -AsPlainText -Force
$cred = New-Object System.Management.Automation.PSCredential ("root", $password)

$session = New-SSHSession -ComputerName 34.80.234.173 -Credential $cred -AcceptKey

# 列出日志目录
$result = Invoke-SSHCommand -SessionId $session.SessionId -Command "ls -lh /www/wwwroot/admin.supergames9.com/runtime/logs/ | head -30"
Write-Host $result.Output

# 查看GameRecordSyncWorker日志
$result = Invoke-SSHCommand -SessionId $session.SessionId -Command "tail -100 /www/wwwroot/admin.supergames9.com/runtime/logs/GameRecordSyncWorker.log"
Write-Host "`n=== GameRecordSyncWorker.log (最后100行) ==="
Write-Host $result.Output

# 查看各平台的错误
$platforms = @('RSG', 'MT', 'DG', 'SA', 'T9SLOT', 'QT', 'KT')
foreach ($platform in $platforms) {
    $result = Invoke-SSHCommand -SessionId $session.SessionId -Command "grep -i 'duplicate\|error' /www/wwwroot/admin.supergames9.com/runtime/logs/*.log 2>/dev/null | grep -i '$platform' | tail -20"
    if ($result.Output) {
        Write-Host "`n=== $platform 平台错误 ==="
        Write-Host $result.Output
    }
}

Remove-SSHSession -SessionId $session.SessionId
