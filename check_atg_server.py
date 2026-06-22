#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ATG解密异常诊断脚本
连接服务器 34.80.234.173 检查ATG相关日志和配置
"""

import paramiko
import sys
import io
from datetime import datetime

# 设置UTF-8输出
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# 服务器配置
HOST = "34.80.234.173"
USER = "root"
PASSWORD = "gang3071"
PROJECT_PATH = "/www/wwwroot/admin.supergames9.com"

def execute_ssh_command(ssh, command):
    """执行SSH命令并返回结果"""
    try:
        stdin, stdout, stderr = ssh.exec_command(command)
        output = stdout.read().decode('utf-8')
        error = stderr.read().decode('utf-8')
        return output, error
    except Exception as e:
        return None, str(e)

def main():
    print(f"正在连接服务器 {HOST}...")

    try:
        # 创建SSH连接
        ssh = paramiko.SSHClient()
        ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        ssh.connect(HOST, username=USER, password=PASSWORD, timeout=10)

        print("[OK] 连接成功!\n")

        # 1. 检查服务器时间
        print("=" * 60)
        print("1. 检查服务器时间")
        print("=" * 60)
        output, error = execute_ssh_command(ssh, "date")
        print(f"服务器时间: {output.strip()}")
        print(f"本地时间:   {datetime.now()}")
        print()

        # 2. 查看最新ATG日志文件
        print("=" * 60)
        print("2. ATG日志文件列表")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"ls -lht {PROJECT_PATH}/runtime/logs/atg_server-*.log 2>/dev/null | head -5")
        print(output if output else "未找到日志文件")
        print()

        # 3. 查看最新的解密错误
        print("=" * 60)
        print("3. 最新的ATG解密相关错误 (最近50条)")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"tail -n 500 {PROJECT_PATH}/runtime/logs/atg_server-*.log 2>/dev/null | grep -E '解密異常|decrypt|error|ERROR|Exception|failed' | tail -50")
        if output:
            print(output)
        else:
            print("未找到解密错误日志")
        print()

        # 4. 查看最新50行原始日志
        print("=" * 60)
        print("4. 最新50行ATG日志")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"tail -n 50 {PROJECT_PATH}/runtime/logs/atg_server-*.log 2>/dev/null")
        if output:
            print(output)
        else:
            print("无法读取日志")
        print()

        # 5. 检查Webman进程状态
        print("=" * 60)
        print("5. Webman进程状态")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"cd {PROJECT_PATH} && php start.php status 2>&1")
        print(output if output else "无法获取进程状态")
        print()

        # 6. 检查Redis连接
        print("=" * 60)
        print("6. Redis连接测试")
        print("=" * 60)
        output, error = execute_ssh_command(ssh, "redis-cli ping 2>&1")
        print(f"Redis状态: {output.strip()}")

        # 检查ATG相关缓存键
        output, error = execute_ssh_command(ssh, "redis-cli KEYS 'atg:*' 2>&1 | head -20")
        print(f"\nATG缓存键示例 (前20个):")
        print(output if output else "无ATG缓存")
        print()

        # 7. 统计解密异常次数
        print("=" * 60)
        print("7. 解密异常统计")
        print("=" * 60)
        output, error = execute_ssh_command(ssh,
            f"grep -c '解密異常' {PROJECT_PATH}/runtime/logs/atg_server-*.log 2>/dev/null || echo 0")
        print(f"总计解密异常次数: {output.strip()}")

        output, error = execute_ssh_command(ssh,
            f"grep '解密異常' {PROJECT_PATH}/runtime/logs/atg_server-*.log 2>/dev/null | tail -5")
        if output:
            print(f"\n最近5次解密异常:")
            print(output)
        print()

        ssh.close()
        print("\n[OK] 诊断完成!")

    except paramiko.AuthenticationException:
        print("[ERROR] 认证失败,请检查用户名和密码")
    except paramiko.SSHException as e:
        print(f"[ERROR] SSH连接错误: {e}")
    except Exception as e:
        print(f"[ERROR] 发生错误: {e}")
        import traceback
        traceback.print_exc()

if __name__ == "__main__":
    main()
