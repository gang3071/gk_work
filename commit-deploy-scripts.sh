#!/bin/bash
# ============================================================
# 本地提交脚本 - 将修复后的部署脚本提交到三个项目
# 在本地 Windows Git Bash 中运行
# ============================================================

echo "=================================================="
echo "📦 开始提交部署脚本到三个项目..."
echo "=================================================="

# 提交单个项目
commit_project() {
    PROJECT_DIR=$1
    BRANCH_NAME=$2
    PROJECT_NAME=$3

    echo ""
    echo "=================================================="
    echo "📦 提交项目: $PROJECT_NAME"
    echo "   目录: $PROJECT_DIR"
    echo "   分支: $BRANCH_NAME"
    echo "=================================================="

    if [ ! -d "$PROJECT_DIR" ]; then
        echo "❌ 目录不存在: $PROJECT_DIR"
        return 1
    fi

    cd "$PROJECT_DIR" || return 1

    # 检查当前分支
    CURRENT_BRANCH=$(git branch --show-current)
    echo "📌 当前分支: $CURRENT_BRANCH"

    if [ "$CURRENT_BRANCH" != "$BRANCH_NAME" ]; then
        echo "⚠️  当前分支不是 $BRANCH_NAME，正在切换..."
        git checkout "$BRANCH_NAME" || return 1
    fi

    # 检查是否有deploy.sh文件
    if [ ! -f "deploy.sh" ]; then
        echo "❌ 未找到 deploy.sh 文件"
        return 1
    fi

    # 添加文件
    echo "📝 添加文件到暂存区..."
    git add deploy.sh

    # 检查是否有其他需要提交的修复脚本
    if [ -f "server-fix-all-projects.sh" ]; then
        git add server-fix-all-projects.sh
        echo "   已添加: server-fix-all-projects.sh"
    fi

    # 检查是否有变更
    if git diff --cached --quiet; then
        echo "⚠️  没有文件需要提交（可能已经提交过了）"
        return 0
    fi

    # 提交
    echo "💾 提交到本地仓库..."
    git commit -m "fix: 修复部署脚本的分支歧义问题

- 使用 remotes/origin/\$BRANCH_NAME 替代 origin/\$BRANCH_NAME
- 避免与错误的本地分支 origin/* 产生歧义
- 自动清理错误的本地分支
- 支持 URL 编码转换 (%2F → /)
- 添加远程分支存在性检查
- 优化日志输出和错误处理"

    if [ $? -eq 0 ]; then
        echo "✅ 提交成功"
    else
        echo "❌ 提交失败"
        return 1
    fi

    # 推送到远程
    echo "🚀 推送到远程仓库..."
    git push origin "$BRANCH_NAME"

    if [ $? -eq 0 ]; then
        echo "✅ 推送成功"
    else
        echo "❌ 推送失败"
        return 1
    fi

    echo "✅ $PROJECT_NAME 完成！"
    return 0
}

# ============================================================
# 提交三个项目
# ============================================================

SUCCESS_COUNT=0
FAIL_COUNT=0

# 1. gk_work
if commit_project "D:/gk_work" "jin" "gk_work"; then
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
else
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

sleep 1

# 2. gk_api
if commit_project "D:/gk_api" "main" "gk_api"; then
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
else
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

sleep 1

# 3. gk_admin
if commit_project "D:/gk_admin" "master" "gk_admin"; then
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
else
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

# ============================================================
# 完成总结
# ============================================================

echo ""
echo ""
echo "=================================================="
echo "🎉 所有项目提交完成！"
echo "=================================================="
echo ""
echo "📊 提交结果统计："
echo "   ✅ 成功: $SUCCESS_COUNT 个项目"
echo "   ❌ 失败: $FAIL_COUNT 个项目"
echo ""
echo "⏰ 完成时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "📝 下一步："
echo "   1. SSH 登录到服务器"
echo "   2. 上传并执行 server-fix-all-projects.sh"
echo "   3. 测试自动部署是否正常工作"
echo ""
echo "=================================================="
echo ""

exit 0
