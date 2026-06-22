#!/usr/bin/env python3
"""生产服务器补充查询"""

import paramiko
import sys

HOST = '34.80.234.173'
PORT = 22
USER = 'root'
PASS = 'gang3071'
LOG_DIR = '/www/wwwroot/admin.supergames9.com/runtime/logs'

def exec_command(ssh, cmd):
    stdin, stdout, stderr = ssh.exec_command(cmd)
    return stdout.read().decode('utf-8', errors='ignore'), stderr.read().decode('utf-8', errors='ignore')

def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    try:
        ssh.connect(HOST, PORT, USER, PASS, timeout=10)
        print("[OK] Connected\n")

        # 补充查询
        commands = {
            "Redis密码配置": "grep -E 'requirepass|masterauth' /etc/redis/redis.conf 2>/dev/null || cat /www/server/redis/redis.conf 2>/dev/null | grep -E 'requirepass|masterauth' || echo 'Config not found'",
            "查看webman.log最新": f"tail -100 {LOG_DIR}/webman.log 2>/dev/null || echo 'File not found'",
            "查看最新game_bet_record日志": f"tail -50 {LOG_DIR}/game_bet_record-2026-06-22.log 2>/dev/null | head -30",
            "检查.env文件Redis配置": "grep 'REDIS' /www/wwwroot/admin.supergames9.com/.env 2>/dev/null | grep -v '^#' || echo 'Not found'",
            "列出所有日志文件": f"ls -lh {LOG_DIR}/*.log 2>/dev/null | grep -E 'GameRecord|worker|sync' || echo 'No matching files'",
            "查找所有Worker日志": f"find {LOG_DIR} -name '*Worker*.log' -o -name '*worker*.log' -o -name '*sync*.log' 2>/dev/null || echo 'Not found'",
            "检查进程日志输出": "ls -lh /www/wwwroot/admin.supergames9.com/runtime/*.log 2>/dev/null || echo 'No runtime logs'",
            "查看workerman.log": "tail -100 /www/wwwroot/admin.supergames9.com/runtime/workerman.log 2>/dev/null || tail -100 /www/wwwroot/admin.supergames9.com/runtime/logs/workerman.log 2>/dev/null || echo 'Not found'",
        }

        for desc, cmd in commands.items():
            print("=" * 70)
            print(f"[{desc}]")
            print("=" * 70)
            output, error = exec_command(ssh, cmd)
            if output:
                print(output)
            else:
                print("(No output)")
            print()

    except Exception as e:
        print(f"[ERROR] {e}")
        sys.exit(1)
    finally:
        ssh.close()

if __name__ == '__main__':
    main()
