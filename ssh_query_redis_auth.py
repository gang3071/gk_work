#!/usr/bin/env python3
"""使用Redis密码认证查询"""

import paramiko

HOST = '34.80.234.173'
USER = 'root'
PASS = 'gang3071'
REDIS_PASS = 'gang3071'

def exec_command(ssh, cmd):
    stdin, stdout, stderr = ssh.exec_command(cmd)
    return stdout.read().decode('utf-8', errors='ignore')

def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    try:
        ssh.connect(HOST, 22, USER, PASS, timeout=10)
        print("[OK] Connected\n")

        # 使用密码认证的Redis命令
        commands = {
            "Queue length": f"redis-cli -h 127.0.0.1 -p 6379 -a {REDIS_PASS} --no-auth-warning ZCARD game:sync:queue",
            "Redis record count": f"redis-cli -h 127.0.0.1 -p 6379 -a {REDIS_PASS} --no-auth-warning KEYS 'game:record:bet:*' | wc -l",
            "Queue sample (first 10)": f"redis-cli -h 127.0.0.1 -p 6379 -a {REDIS_PASS} --no-auth-warning ZRANGE game:sync:queue 0 9",
            "Check specific record": f"redis-cli -h 127.0.0.1 -p 6379 -a {REDIS_PASS} --no-auth-warning ZRANGE game:sync:queue 0 0",
            "Check status of first record": "",  # Will be filled dynamically
            "Find Worker log files": "find /www/wwwroot/admin.supergames9.com -name '*yncWorker*.log' -o -name '*ync*.log' 2>/dev/null",
            "Check runtime directory": "ls -lh /www/wwwroot/admin.supergames9.com/runtime/ 2>/dev/null",
            "Check process config": "cat /www/wwwroot/admin.supergames9.com/config/process.php 2>/dev/null | grep -A 10 'GameRecordSyncWorker'",
            "Check log config": "cat /www/wwwroot/admin.supergames9.com/config/log.php 2>/dev/null | grep -i 'gamerecord' || echo 'No specific config'",
        }

        results = {}
        for desc, cmd in commands.items():
            if not cmd:
                continue
            print("=" * 70)
            print(f"[{desc}]")
            print("=" * 70)
            output = exec_command(ssh, cmd)
            results[desc] = output
            print(output if output else "(No output)")
            print()

        # Get first queue item and check its details
        first_key_cmd = f"redis-cli -h 127.0.0.1 -p 6379 -a {REDIS_PASS} --no-auth-warning ZRANGE game:sync:queue 0 0"
        first_key = exec_command(ssh, first_key_cmd).strip()
        if first_key:
            print("=" * 70)
            print("[First queue record details]")
            print("=" * 70)
            detail_cmd = f"redis-cli -h 127.0.0.1 -p 6379 -a {REDIS_PASS} --no-auth-warning HGETALL '{first_key}'"
            details = exec_command(ssh, detail_cmd)
            print(f"Key: {first_key}")
            print(details)
            print()

    except Exception as e:
        print(f"[ERROR] {e}")
    finally:
        ssh.close()

if __name__ == '__main__':
    main()
