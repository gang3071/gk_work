<?php

namespace app\service\store;

use app\model\Currency;
use app\model\PlayerDeliveryRecord;
use app\model\StoreAgentShiftHandoverRecord;
use app\model\StoreAutoShiftConfig;
use app\model\StoreAutoShiftLog;
use Carbon\Carbon;
use support\Db;

/**
 * 自动交班服务
 */
class AutoShiftService
{
    /**
     * 检查是否启用自动交班
     */
    public function isAutoShiftEnabled(int $departmentId, int $bindAdminUserId): bool
    {
        $config = StoreAutoShiftConfig::query()
            ->where('department_id', $departmentId)
            ->where('bind_admin_user_id', $bindAdminUserId)
            ->where('is_enabled', 1)
            ->first();

        return $config !== null;
    }

    /**
     * 获取自动交班配置
     */
    public function getConfig(int $departmentId, int $bindAdminUserId)
    {
        return StoreAutoShiftConfig::query()
            ->where('department_id', $departmentId)
            ->where('bind_admin_user_id', $bindAdminUserId)
            ->first();
    }

    /**
     * 保存/更新自动交班配置
     */
    public function saveConfig(array $data): array
    {
        try {
            DB::beginTransaction();

            $config = StoreAutoShiftConfig::query()
                ->where('department_id', $data['department_id'])
                ->where('bind_admin_user_id', $data['bind_admin_user_id'])
                ->first();

            if (!$config) {
                $config = new StoreAutoShiftConfig();
                $config->department_id = $data['department_id'];
                $config->bind_admin_user_id = $data['bind_admin_user_id'];
            }

            // 更新配置
            $config->is_enabled = $data['is_enabled'] ?? 0;
            $config->shift_time_1 = $data['shift_time_1'] ?? '08:00:00';
            $config->shift_time_2 = $data['shift_time_2'] ?? '16:00:00';
            $config->shift_time_3 = $data['shift_time_3'] ?? '00:00:00';
            $config->auto_settlement = $data['auto_settlement'] ?? 1;

            // 验证配置
            $validation = $this->validateConfig($config);
            if (!$validation['valid']) {
                DB::rollBack();
                return ['code' => 1, 'msg' => $validation['message']];
            }

            // 如果启用，计算下次交班时间
            if ($config->is_enabled) {
                $config->next_shift_time = $this->calculateNextShiftTime($config);
            } else {
                $config->next_shift_time = null;
            }

            $config->save();

            DB::commit();

            \Log::info('保存自动交班配置成功', [
                'department_id' => $data['department_id'],
                'bind_admin_user_id' => $data['bind_admin_user_id'],
                'is_enabled' => $config->is_enabled,
                'next_shift_time' => $config->next_shift_time
            ]);

            return ['code' => 0, 'msg' => '保存成功', 'data' => $config];

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('保存自动交班配置失败', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['code' => 1, 'msg' => '保存失败: ' . $e->getMessage()];
        }
    }

    /**
     * 验证配置
     */
    private function validateConfig(StoreAutoShiftConfig $config): array
    {
        // 验证时间格式
        foreach (['shift_time_1', 'shift_time_2', 'shift_time_3'] as $field) {
            if (!empty($config->$field)) {
                if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $config->$field)) {
                    return ['valid' => false, 'message' => '交班时间格式错误'];
                }
            }
        }

        return ['valid' => true];
    }

    /**
     * 计算下次交班时间
     * 从3个交班时间（早班08:00、中班16:00、晚班00:00）中找到最近的一个
     */
    public function calculateNextShiftTime(StoreAutoShiftConfig $config): ?Carbon
    {
        $now = Carbon::now();
        $times = [];

        // 收集所有设置的交班时间（早班、中班、晚班）
        foreach (['shift_time_1', 'shift_time_2', 'shift_time_3'] as $field) {
            if (!empty($config->$field)) {
                $time = Carbon::parse($config->$field);
                $next = Carbon::today()->setTime($time->hour, $time->minute, $time->second);

                // 如果时间已过，则为明天同一时间
                if ($next->lte($now)) {
                    $next->addDay();
                }

                $times[] = $next;
            }
        }

        // 如果没有设置任何时间，返回null（理论上不会发生，因为有默认值）
        if (empty($times)) {
            return null;
        }

        // 返回最近的时间
        usort($times, function ($a, $b) {
            return $a->timestamp <=> $b->timestamp;
        });

        return $times[0];
    }

    /**
     * 执行自动交班
     */
    public function executeAutoShift(StoreAutoShiftConfig $config): array
    {
        $startExecute = microtime(true);
        $startTime = null;
        $endTime = null;

        try {
            DB::beginTransaction();

            // 1. 锁定配置记录（防止并发执行）
            $config = StoreAutoShiftConfig::query()
                ->where('id', $config->id)
                ->lockForUpdate()
                ->first();

            if (!$config || !$config->is_enabled) {
                DB::rollBack();
                return ['code' => 1, 'msg' => '配置已禁用'];
            }

            // 2. 计算交班时间范围
            $endTime = Carbon::now();

            // 如果有上次交班时间，从上次结束时间开始
            if ($config->last_shift_time) {
                $lastRecord = StoreAgentShiftHandoverRecord::query()
                    ->where('bind_admin_user_id', $config->bind_admin_user_id)
                    ->where('is_auto_shift', 1)
                    ->orderBy('id', 'desc')
                    ->first();

                $startTime = $lastRecord
                    ? Carbon::parse($lastRecord->end_time)
                    : Carbon::parse($config->last_shift_time);
            } else {
                // 第一次交班，默认统计最近24小时
                $startTime = $endTime->copy()->subDay();
            }

            // 3. 检查时间有效性
            if ($startTime->gte($endTime)) {
                DB::rollBack();
                return ['code' => 1, 'msg' => '交班时间范围无效'];
            }

            // 限制最大时间跨度（30天）
            if ($startTime->diffInDays($endTime) > 30) {
                DB::rollBack();
                return ['code' => 1, 'msg' => '交班时间跨度不能超过30天'];
            }

            // 4. 统计账变数据
            $statistics = $this->calculateShiftStatistics(
                $config->bind_admin_user_id,
                $startTime->toDateTimeString(),
                $endTime->toDateTimeString()
            );

            // 5. 创建交班记录
            $shiftRecord = new StoreAgentShiftHandoverRecord();
            $shiftRecord->department_id = $config->department_id;
            $shiftRecord->bind_admin_user_id = $config->bind_admin_user_id;
            $shiftRecord->start_time = $startTime;
            $shiftRecord->end_time = $endTime;
            $shiftRecord->machine_amount = $statistics['machine_amount'];
            $shiftRecord->machine_point = $statistics['machine_point'];
            $shiftRecord->total_in = $statistics['total_in'];
            $shiftRecord->total_out = $statistics['total_out'];
            $shiftRecord->total_profit_amount = $statistics['total_profit'];
            $shiftRecord->is_auto_shift = 1;
            $shiftRecord->save();

            // 6. 创建执行日志
            $duration = (microtime(true) - $startExecute) * 1000;

            $log = new StoreAutoShiftLog();
            $log->config_id = $config->id;
            $log->department_id = $config->department_id;
            $log->bind_admin_user_id = $config->bind_admin_user_id;
            $log->shift_record_id = $shiftRecord->id;
            $log->start_time = $startTime;
            $log->end_time = $endTime;
            $log->execute_time = Carbon::now();
            $log->status = StoreAutoShiftLog::STATUS_SUCCESS;
            $log->execution_duration = (int)$duration;
            $log->machine_amount = $statistics['machine_amount'];
            $log->machine_point = $statistics['machine_point'];
            $log->total_in = $statistics['total_in'];
            $log->total_out = $statistics['total_out'];
            $log->total_profit = $statistics['total_profit'];
            $log->save();

            // 7. 更新配置
            $shiftRecord->auto_shift_log_id = $log->id;
            $shiftRecord->save();

            $config->last_shift_time = $endTime;
            $config->next_shift_time = $this->calculateNextShiftTime($config);
            $config->save();

            DB::commit();

            \Log::info('自动交班成功', [
                'config_id' => $config->id,
                'shift_record_id' => $shiftRecord->id,
                'time_range' => $startTime->toDateTimeString() . ' ~ ' . $endTime->toDateTimeString(),
                'duration' => round($duration, 2) . 'ms',
                'total_profit' => $statistics['total_profit']
            ]);

            return [
                'code' => 0,
                'msg' => '自动交班成功',
                'data' => [
                    'shift_record_id' => $shiftRecord->id,
                    'log_id' => $log->id,
                    'statistics' => $statistics
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            // 记录失败日志
            $duration = (microtime(true) - $startExecute) * 1000;

            try {
                $log = new StoreAutoShiftLog();
                $log->config_id = $config->id;
                $log->department_id = $config->department_id;
                $log->bind_admin_user_id = $config->bind_admin_user_id;
                $log->start_time = $startTime ?? Carbon::now();
                $log->end_time = $endTime ?? Carbon::now();
                $log->execute_time = Carbon::now();
                $log->status = StoreAutoShiftLog::STATUS_FAILED;
                $log->error_message = $e->getMessage();
                $log->execution_duration = (int)$duration;
                $log->save();
            } catch (\Exception $logError) {
                \Log::error('记录失败日志时出错', ['error' => $logError->getMessage()]);
            }

            \Log::error('自动交班失败', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return ['code' => 1, 'msg' => '自动交班失败: ' . $e->getMessage()];
        }
    }

    /**
     * 统计交班数据
     */
    private function calculateShiftStatistics(int $bindAdminUserId, string $startTime, string $endTime): array
    {
        $currency = Currency::query()->first();

        if (!$currency) {
            throw new \Exception('系统配置错误：货币配置不存在');
        }

        $result = PlayerDeliveryRecord::query()
            ->selectRaw('
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as present_in_amount,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as present_out_amount,
                SUM(CASE WHEN type = ? THEN point ELSE 0 END) as machine_put_point
            ', [
                PlayerDeliveryRecord::TYPE_PRESENT_IN,
                PlayerDeliveryRecord::TYPE_PRESENT_OUT,
                PlayerDeliveryRecord::TYPE_MACHINE
            ])
            ->join('player', 'player_delivery_record.player_id', '=', 'player.id')
            ->where('player.department_id', function ($query) use ($bindAdminUserId) {
                $query->select('department_id')
                    ->from('player')
                    ->where('id', $bindAdminUserId)
                    ->limit(1);
            })
            ->where('player_delivery_record.created_at', '>', $startTime)
            ->where('player_delivery_record.created_at', '<=', $endTime)
            ->first();

        $data = $result ? $result->toArray() : [
            'present_in_amount' => 0,
            'present_out_amount' => 0,
            'machine_put_point' => 0
        ];

        $machineAmount = bcdiv($data['machine_put_point'], $currency->rate, 2);
        $totalProfit = bcsub($data['present_in_amount'], $data['present_out_amount'], 2);

        return [
            'machine_amount' => (float)$machineAmount,
            'machine_point' => (int)$data['machine_put_point'],
            'total_in' => (float)$data['present_in_amount'],
            'total_out' => (float)$data['present_out_amount'],
            'total_profit' => (float)$totalProfit
        ];
    }

    /**
     * 获取待执行的配置列表
     */
    public function getPendingConfigs(): array
    {
        return StoreAutoShiftConfig::query()
            ->where('is_enabled', 1)
            ->where('next_shift_time', '<=', Carbon::now())
            ->get()
            ->toArray();
    }

    /**
     * 获取执行统计
     */
    public function getExecutionStats(int $departmentId, int $bindAdminUserId, int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $logs = StoreAutoShiftLog::query()
            ->where('department_id', $departmentId)
            ->where('bind_admin_user_id', $bindAdminUserId)
            ->where('created_at', '>=', $startDate)
            ->get();

        $total = $logs->count();
        $success = $logs->where('status', StoreAutoShiftLog::STATUS_SUCCESS)->count();
        $failed = $logs->where('status', StoreAutoShiftLog::STATUS_FAILED)->count();
        $avgDuration = $logs->avg('execution_duration');

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round($success / $total * 100, 2) : 0,
            'avg_duration' => round($avgDuration, 2),
            'avg_duration_text' => round($avgDuration / 1000, 2) . 's'
        ];
    }
}
