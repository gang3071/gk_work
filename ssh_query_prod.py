#!/usr/bin/env python3
"""
生产服务器SSH查询脚本
使用paramiko库进行SSH连接
"""

try:
    import paramiko
except ImportError:
    print("正在安装paramiko...")
    import subprocess
    subprocess.check_call(['pip', 'install', 'paramiko'])
    import paramiko

import sys

# 连接配置
HOST = '34.80.234.173'
PORT = 22
USER = 'root'
PASS = 'gang3071'
LOG_DIR = '/www/wwwroot/admin.supergames9.com/runtime/logs'

def exec_command(ssh, cmd):
    """执行SSH命令并返回输出"""
    stdin, stdout, stderr = ssh.exec_command(cmd)
    output = stdout.read().decode('utf-8', errors='ignore')
    error = stderr.read().decode('utf-8', errors='ignore')
    return output, error

def main():
    print("=== 连接到生产服务器 {} ===\n".format(HOST))

    # 创建SSH客户端
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    try:
        # 连接
        ssh.connect(HOST, PORT, USER, PASS, timeout=10)
        print("[OK] 连接成功\n")

        # 定义要执行的命令
        commands = {
            "1. 检查队列积压数量": "redis-cli -h 127.0.0.1 -p 6379 ZCARD game:sync:queue",
            "2. 检查Redis记录总数": "redis-cli -h 127.0.0.1 -p 6379 KEYS 'game:record:bet:*' | wc -l",
            "3. 查看Worker进程": "ps aux | grep GameRecordSyncWorker | grep -v grep",
            "4. GameRecordSyncWorker最新日志": f"tail -200 {LOG_DIR}/GameRecordSyncWorker.log",
            "5. 查找duplicate错误": f"grep -i 'duplicate' {LOG_DIR}/*.log 2>/dev/null | tail -50",
            "6. 查找EVALSHA问题": f"grep -iE 'evalsha|noscript|script.*load' {LOG_DIR}/GameRecordSyncWorker.log 2>/dev/null | tail -30",
            "7. 统计各平台记录(RSG)": f"grep -c 'RSG' {LOG_DIR}/GameRecordSyncWorker.log 2>/dev/null || echo 0",
            "8. 统计各平台记录(T9SLOT)": f"grep -c 'T9SLOT' {LOG_DIR}/GameRecordSyncWorker.log 2>/dev/null || echo 0",
            "9. 统计各平台记录(DG)": f"grep -c 'DG' {LOG_DIR}/GameRecordSyncWorker.log 2>/dev/null || echo 0",
            "10. 查看批次处理日志": f"grep -E '批次处理|读取.*条记录|插入.*成功|去重|合并' {LOG_DIR}/GameRecordSyncWorker.log 2>/dev/null | tail -50",
            "11. 抽样查看队列记录": "redis-cli -h 127.0.0.1 -p 6379 ZRANGE game:sync:queue 0 9",
            "12. 检查日志文件大小": f"ls -lh {LOG_DIR}/ | head -20",
        }

        for desc, cmd in commands.items():
            print("=" * 70)
            print(f"[{desc}]")
            print("=" * 70)
            print(f"执行: {cmd}\n")

            output, error = exec_command(ssh, cmd)

            if output:
                print(output)
            else:
                print("(无输出)")

            if error and 'grep' not in error:
                print(f"错误: {error}")

            print()

    except Exception as e:
        print(f"[ERROR] 错误: {e}")
        sys.exit(1)
    finally:
        ssh.close()

if __name__ == '__main__':
    main()
