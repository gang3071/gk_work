#!/bin/bash
# ============================================================
# 服务器端一次性修复脚本
# 用于修复三个项目的部署问题并拉取最新代码
# 使用方法：在服务器上执行 bash server-fix-all-projects.sh
# ============================================================

echo "=================================================="
echo "🔧 开始修复三个项目的部署问题..."
echo "=================================================="

# 修复单个项目的函数
fix_project() {
    PROJECT_DIR=$1
    BRANCH_NAME=$2
    PROJECT_NAME=$3

    echo ""
    echo "=================================================="
    echo "🔧 修复项目: $PROJECT_NAME"
    echo "   目录: $PROJECT_DIR"
    echo "   分支: $BRANCH_NAME"
    echo "=================================================="

    # 检查目录是否存在
    if [ ! -d "$PROJECT_DIR" ]; then
        echo "❌ 目录不存在: $PROJECT_DIR"
        echo "   跳过此项目..."
        return 1
    fi

    cd "$PROJECT_DIR" || return 1

    # 1. 删除错误的 origin/* 本地分支
    echo ""
    echo "📌 步骤 1/6: 清理错误的本地分支..."
    REMOVED_COUNT=0
    for branch in $(git branch | grep "origin/"); do
        echo "   删除错误分支: $branch"
        git branch -D $branch 2>/dev/null && REMOVED_COUNT=$((REMOVED_COUNT + 1))
    done
    if [ $REMOVED_COUNT -gt 0 ]; then
        echo "✅ 已删除 $REMOVED_COUNT 个错误分支"
    else
        echo "✅ 没有发现错误分支"
    fi

    # 2. 清理本地修改
    echo ""
    echo "📌 步骤 2/6: 清理本地未提交的修改..."
    git reset --hard HEAD
    git clean -fd
    echo "✅ 清理完成"

    # 3. 拉取最新代码
    echo ""
    echo "📌 步骤 3/6: 拉取远程最新代码..."
    git fetch --prune --all
    echo "✅ 拉取完成"

    # 4. 检查远程分支是否存在
    echo ""
    echo "📌 步骤 4/6: 检查远程分支..."
    if ! git rev-parse --verify "remotes/origin/$BRANCH_NAME" >/dev/null 2>&1; then
        echo "❌ 远程分支 origin/$BRANCH_NAME 不存在！"
        echo ""
        echo "可用的远程分支："
        git branch -r | grep "origin/" | head -10
        return 1
    fi
    echo "✅ 远程分支存在"

    # 5. 强制更新到最新代码
    echo ""
    echo "📌 步骤 5/6: 强制同步到最新代码..."
    git checkout -f -B $BRANCH_NAME
    git reset --hard remotes/origin/$BRANCH_NAME

    # 验证
    CURRENT_COMMIT=$(git rev-parse --short HEAD)
    echo "✅ 已更新到提交: $CURRENT_COMMIT"
    echo ""
    echo "   最新的 3 条提交记录："
    echo "   --------------------------------------------------"
    git log --oneline -3 | sed 's/^/   /'
    echo "   --------------------------------------------------"

    # 6. 设置脚本权限
    echo ""
    echo "📌 步骤 6/6: 设置部署脚本权限..."
    if [ -f "deploy.sh" ]; then
        chmod +x deploy.sh
        echo "✅ deploy.sh 已设置执行权限"
    else
        echo "⚠️  未找到 deploy.sh 文件"
    fi

    # 7. 更新 Composer 依赖
    echo ""
    echo "📦 更新 Composer 依赖..."
    export HOME=/root
    export COMPOSER_HOME=/root/.config/composer
    export COMPOSER_ALLOW_SUPERUSER=1

    composer install \
        --no-interaction \
        --no-dev \
        --optimize-autoloader \
        --ignore-platform-req=ext-mongodb \
        --quiet 2>&1 | grep -v "Package.*is abandoned" | grep -v "Warning: Ambiguous"

    if [ $? -eq 0 ]; then
        echo "✅ Composer 依赖更新成功"
    else
        echo "⚠️  Composer 依赖更新失败，但继续..."
    fi

    # 8. 重载服务
    echo ""
    echo "🔄 重载 Webman 服务..."

    if php start.php status 2>&1 | grep -q "not run"; then
        echo "⚠️  服务未运行，尝试启动..."
        php start.php start -d
        SERVICE_ACTION="started"
    else
        php start.php reload
        SERVICE_ACTION="reloaded"
    fi

    if [ $? -eq 0 ]; then
        echo "✅ Webman 服务已 $SERVICE_ACTION"
    else
        echo "❌ Webman 服务操作失败！"
    fi

    echo ""
    echo "✅ $PROJECT_NAME 修复完成！"
    echo "=================================================="

    return 0
}

# ============================================================
# 修复三个项目
# ============================================================

SUCCESS_COUNT=0
FAIL_COUNT=0

# 1. 修复 gk_work
if fix_project "/www/wwwroot/work.5super9.com" "jin" "gk_work (任务和单一钱包API)"; then
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
else
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

sleep 2

# 2. 修复 gk_api
if fix_project "/www/wwwroot/api-test.5super9.com" "main" "gk_api (API服务)"; then
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
else
    FAIL_COUNT=$((FAIL_COUNT + 1))
fi

sleep 2

# 3. 修复 gk_admin
if fix_project "/www/wwwroot/admin-test.5super9.com" "master" "gk_admin (后台管理系统)"; then
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
echo "🎉 所有项目修复完成！"
echo "=================================================="
echo ""
echo "📊 修复结果统计："
echo "   ✅ 成功: $SUCCESS_COUNT 个项目"
echo "   ❌ 失败: $FAIL_COUNT 个项目"
echo ""
echo "⏰ 完成时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""
echo "📝 后续步骤："
echo "   1. 检查上面的日志，确认所有项目都已成功更新"
echo "   2. 测试各个服务是否正常运行"
echo "   3. 在本地推送代码测试自动部署是否工作"
echo ""
echo "=================================================="
echo ""

exit 0
