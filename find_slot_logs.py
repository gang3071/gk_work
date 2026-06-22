#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
查找slot_machine日志文件
"""

import paramiko
import sys
import io

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

HOST = "34.80.234.173"
USER = "root"
PASSWORD = "gang3071"
PROJECT_PATH = "/www/wwwroot/admin.supergames9.com"

def execute_ssh_command(ssh, command):
    try:
        stdin, stdout, stderr = ssh.exec_command(command)
        output = stdout.read().decode('utf-8')
        error = stderr.read().decode('utf-8')
        return output, error
    except Exception as e:
        return None, str(e)

def main():
    print(f"正在连接服务器 {HOST}...\n")

    try:
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(HOST, username=USER, password=PASSWORD, timeout=10)

        print("[OK] 连接成功!\n")

        # 1. 列出所有日志文件
        print("=" * 60)
        print("1. 查看runtime/logs目录下的所有日志文件")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"ls -lht {PROJECT_PATH}/runtime/logs/*.log 2>/dev/null | head -30")
        if output:
            print(output)
        else:
            print("未找到日志文件")
        print()

        # 2. 搜索包含"slot"的日志文件
        print("=" * 60)
        print("2. 搜索包含slot的日志文件")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"ls -lh {PROJECT_PATH}/runtime/logs/*slot* 2>/dev/null")
        if output:
            print(output)
        else:
            print("未找到slot相关日志文件")
        print()

        # 3. 检查配置文件中的日志配置
        print("=" * 60)
        print("3. 查看日志配置文件")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"cat {PROJECT_PATH}/config/log.php 2>/dev/null | grep -A 3 slot")
        if output:
            print(output)
        else:
            print("未找到slot_machine日志配置")
        print()

        # 4. 查找最近的错误日志
        print("=" * 60)
        print("4. 查找包含gmstrftime或M174的日志")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"grep -r 'gmstrftime\\|M174\\|指令超时\\|消息处理错误' {PROJECT_PATH}/runtime/logs/*.log 2>/dev/null | tail -20")
        if output:
            print(output)
        else:
            print("未找到相关错误")
        print()

        # 5. 查看workerman.log
        print("=" * 60)
        print("5. 查看workerman.log中的错误")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"tail -n 100 {PROJECT_PATH}/runtime/logs/workerman.log 2>/dev/null | grep -E 'ERROR|Exception|gmstrftime|M174' | tail -20")
        if output:
            print(output)
        else:
            print("未找到错误")
        print()

        ssh.close()
        print("\n[OK] 诊断完成!")

    except Exception as e:
        print(f"[ERROR] 发生错误: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    main()
