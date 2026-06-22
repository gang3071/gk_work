#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
查找老虎机错误日志(使用正确的文件名)
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

        # 1. 列出老虎机相关日志文件
        print("=" * 60)
        print("1. 老虎机日志文件列表")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"ls -lh {PROJECT_PATH}/runtime/logs/*slot* {PROJECT_PATH}/runtime/logs/*machine* 2>/dev/null")
        if output:
            print(output)
        else:
            print("未找到日志文件")
        print()

        # 2. 查找slot_machine_log.log中的错误
        print("=" * 60)
        print("2. slot_machine_log.log 中的错误")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"tail -n 200 {PROJECT_PATH}/runtime/logs/slot_machine_log.log 2>/dev/null | grep -E 'ERROR|Exception' | tail -30")
        if output:
            print(output)
        else:
            print("未找到错误或文件不存在")
        print()

        # 3. 查找song_slot_machine.log中的错误
        print("=" * 60)
        print("3. song_slot_machine.log 中的错误")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"tail -n 200 {PROJECT_PATH}/runtime/logs/song_slot_machine.log 2>/dev/null | grep -E 'ERROR|Exception' | tail -30")
        if output:
            print(output)
        else:
            print("未找到错误或文件不存在")
        print()

        # 4. 搜索gmstrftime错误
        print("=" * 60)
        print("4. 搜索gmstrftime废弃警告")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"grep -h 'gmstrftime' {PROJECT_PATH}/runtime/logs/*machine*.log 2>/dev/null | tail -10")
        if output:
            print(output)
        else:
            print("未找到gmstrftime警告")
        print()

        # 5. 搜索M174机台
        print("=" * 60)
        print("5. 搜索M174机台相关日志")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"grep -h 'M174' {PROJECT_PATH}/runtime/logs/*machine*.log {PROJECT_PATH}/runtime/logs/*slot*.log 2>/dev/null | tail -20")
        if output:
            print(output)
        else:
            print("未找到M174相关日志")
        print()

        # 6. 搜索指令23
        print("=" * 60)
        print("6. 搜索指令23相关日志")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"grep -h 'action.*23\\|指令.*23' {PROJECT_PATH}/runtime/logs/*machine*.log {PROJECT_PATH}/runtime/logs/*slot*.log 2>/dev/null | tail -20")
        if output:
            print(output)
        else:
            print("未找到指令23相关日志")
        print()

        # 7. 查看最近的所有老虎机日志错误
        print("=" * 60)
        print("7. 最近50行老虎机ERROR日志")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"grep -h 'ERROR' {PROJECT_PATH}/runtime/logs/*machine*.log {PROJECT_PATH}/runtime/logs/*slot*.log 2>/dev/null | tail -50")
        if output:
            print(output)
        else:
            print("未找到ERROR日志")
        print()

        ssh.close()
        print("\n[OK] 诊断完成!")

    except Exception as e:
        print(f"[ERROR] 发生错误: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    main()
