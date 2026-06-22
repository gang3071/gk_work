#!/bin/bash
# Redis 内存碎片修复脚本
# 在服务器上执行: bash fix_redis_fragmentation.sh

echo "====================================="
echo "Redis 内存碎片修复"
echo "====================================="

# 1. 检查当前碎片率
echo -e "\n1. 当前内存碎片率："
redis-cli INFO memory | grep mem_fragmentation_ratio

# 2. 启用主动碎片整理（Redis 4.0+）
echo -e "\n2. 启用主动碎片整理..."
redis-cli CONFIG SET activedefrag yes
redis-cli CONFIG SET active-defrag-ignore-bytes 100mb
redis-cli CONFIG SET active-defrag-threshold-lower 10
redis-cli CONFIG SET active-defrag-threshold-upper 100
redis-cli CONFIG SET active-defrag-cycle-min 5
redis-cli CONFIG SET active-defrag-cycle-max 75

echo "✅ 主动碎片整理已启用"

# 3. 等待一段时间让碎片整理生效
echo -e "\n3. 等待 10 秒让碎片整理生效..."
sleep 10

# 4. 再次检查碎片率
echo -e "\n4. 整理后的碎片率："
redis-cli INFO memory | grep mem_fragmentation_ratio

# 5. 永久保存配置（写入 redis.conf）
echo -e "\n5. 建议将以下配置写入 /etc/redis/redis.conf："
echo "-----------------------------------"
echo "activedefrag yes"
echo "active-defrag-ignore-bytes 100mb"
echo "active-defrag-threshold-lower 10"
echo "active-defrag-threshold-upper 100"
echo "active-defrag-cycle-min 5"
echo "active-defrag-cycle-max 75"
echo "-----------------------------------"

echo -e "\n✅ 修复完成！"
echo "建议监控碎片率，如果仍然很高，考虑重启 Redis 服务"
