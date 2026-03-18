<?php

namespace app\service;

use Exception;

/**
 * 极低概率中奖检查服务类
 * 支持 decimal(10,9) 格式的概率（0.000000001 到 1.0）
 */
class LotteryProbabilityService
{
    /**
     * @var int 默认精度（小数点后位数）
     */
    private int $precision;

    /**
     * @var int 概率池大小
     */
    private int $poolSize;

    /**
     * @var bool 是否启用BCMath扩展
     */
    private bool $useBCMath;

    /**
     * @var array 统计信息
     */
    private array $statistics;

    /**
     * 构造函数
     *
     * @param int $precision 精度，默认9位小数
     * @param int $poolSize 概率池大小，默认10亿
     */
    public function __construct(int $precision = 9, int $poolSize = 1000000000)
    {
        $this->precision = $precision;
        $this->poolSize = $poolSize;
        $this->useBCMath = extension_loaded('bcmath');

        // 初始化算法使用统计
        $this->statistics = [
            'algorithm_usage' => [
                'big_int' => 0,
                'high_precision' => 0,
                'probability_pool' => 0,
                'bcmath' => 0,
                'simple' => 0,
                'smart' => 0
            ]
        ];
    }

    /**
     * 方法1：使用大整数处理极低概率（核心算法）
     * 推荐用于极低概率场景，精度最高
     *
     * @param float $winRatio 中奖概率
     * @return bool 是否中奖
     * @throws Exception
     */
    public function checkByBigInt(float $winRatio): bool
    {
        // 边界检查
        if ($winRatio <= 0) {
            return false;
        }
        if ($winRatio >= 1) {
            return true;
        }

        // 计算精度倍数
        $scale = pow(10, $this->precision);

        // 将概率转换为整数
        $ratioInt = (int)($winRatio * $scale);

        // 如果转换后为0，说明概率小于最小精度
        if ($ratioInt <= 0) {
            return false;
        }

        // 生成随机整数（范围：0 到 scale-1）
        $random = random_int(0, (int)$scale - 1);

        // 当 random < ratioInt 时中奖
        return $random < $ratioInt;
    }

    /**
     * 方法2：高精度浮点数比较
     * 使用 random_int 生成高精度随机数
     *
     * @param float $winRatio 中奖概率
     * @return bool 是否中奖
     * @throws Exception
     */
    public function checkByHighPrecision(float $winRatio): bool
    {
        // 边界检查
        if ($winRatio <= 0) {
            return false;
        }
        if ($winRatio >= 1) {
            return true;
        }

        // 生成高精度随机数（0到1之间）
        // 使用 random_int 生成整数，然后除以最大值得到浮点数
        $random = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;

        return $random <= $winRatio;
    }

    /**
     * 方法3：概率池算法
     * 适用于中低概率场景
     *
     * @param float $winRatio 中奖概率
     * @return bool 是否中奖
     * @throws Exception
     */
    public function checkByProbabilityPool(float $winRatio): bool
    {
        // 边界检查
        if ($winRatio <= 0) {
            return false;
        }
        if ($winRatio >= 1) {
            return true;
        }

        // 计算概率池中的中奖签数量
        $winningTickets = (int)($winRatio * $this->poolSize);

        // 处理小于 1/poolSize 的极低概率
        if ($winningTickets <= 0) {
            // 对于极低概率，使用高精度算法
            return $this->checkByBigInt($winRatio);
        }

        // 从概率池中随机抽取（范围：1 到 poolSize）
        $draw = random_int(1, $this->poolSize);

        // 抽取的数字 <= 中奖签数量时中奖
        return $draw <= $winningTickets;
    }

    /**
     * 方法4：基于BCMath扩展的高精度算法
     * 使用字符串比较保证最高精度
     *
     * @param float $winRatio 中奖概率
     * @return bool 是否中奖
     * @throws Exception 如果BCMath扩展未启用
     */
    public function checkByBCMath(float $winRatio): bool
    {
        if (!$this->useBCMath) {
            throw new Exception('BCMath扩展未启用，无法使用此算法');
        }

        // 转换为字符串，确保精度
        $ratioStr = number_format($winRatio, $this->precision, '.', '');

        // 边界检查
        if (bccomp($ratioStr, '0', $this->precision) <= 0) {
            return false;
        }
        if (bccomp($ratioStr, '1', $this->precision) >= 0) {
            return true;
        }

        // 生成高精度随机数（使用字符串格式）
        $randomStr = '0.';
        for ($i = 1; $i <= $this->precision; $i++) {
            $randomStr .= random_int(0, 9);
        }

        // 使用BCMath进行高精度比较
        return bccomp($randomStr, $ratioStr, $this->precision) <= 0;
    }

    /**
     * 方法5：简单随机比较（适用于较高概率）
     * 使用 random_int 保证精度
     *
     * @param float $winRatio 中奖概率
     * @return bool 是否中奖
     * @throws Exception
     */
    public function checkBySimple(float $winRatio): bool
    {
        if ($winRatio <= 0) {
            return false;
        }
        if ($winRatio >= 1) {
            return true;
        }

        // 使用 random_int 代替 mt_rand 以获得更好的精度
        $random = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
        return $random <= $winRatio;
    }

    /**
     * 智能选择算法（主方法）
     * 根据概率大小自动选择最佳算法
     *
     * @param float $winRatio 中奖概率
     * @return bool 是否中奖
     * @throws Exception
     */
    public function checkSmart(float $winRatio): bool
    {
        // 边界检查
        if ($winRatio <= 0) {
            return false;
        }
        if ($winRatio >= 1) {
            return true;
        }

        // 根据概率大小选择最佳算法
        // 使用 checkByBigInt 作为主要算法，它在所有概率范围都表现良好
        if ($winRatio < 0.000001) {
            // 百万分之一以下的极低概率：使用大整数算法（最精确）
            $result = $this->checkByBigInt($winRatio);
            $this->statistics['algorithm_usage']['big_int']++;
        } elseif ($winRatio < 0.0001) {
            // 万分之一以下的低概率：使用概率池算法
            $result = $this->checkByProbabilityPool($winRatio);
            $this->statistics['algorithm_usage']['probability_pool']++;
        } elseif ($winRatio < 0.01) {
            // 百分之一以下的中低概率：使用高精度算法
            $result = $this->checkByHighPrecision($winRatio);
            $this->statistics['algorithm_usage']['high_precision']++;
        } else {
            // 较高概率（>= 1%）：使用简单算法
            $result = $this->checkBySimple($winRatio);
            $this->statistics['algorithm_usage']['simple']++;
        }

        $this->statistics['algorithm_usage']['smart']++;
        return $result;
    }

    /**
     * 获取统计信息
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * 重置统计信息
     *
     * @return void
     */
    public function resetStatistics(): void
    {
        $this->statistics['algorithm_usage'] = [
            'big_int' => 0,
            'high_precision' => 0,
            'probability_pool' => 0,
            'bcmath' => 0,
            'simple' => 0,
            'smart' => 0
        ];
    }
}
