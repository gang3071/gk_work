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
    public function isAutoShiftEnabled(int $departmentId, int $bindPlayerId): bool
    {
        $config = StoreAutoShiftConfig::query()
            ->where('department_id', $departmentId)
            ->where('bind_player_id', $bindPlayerId)
            ->where('is_enabled', 1)
            ->where('status', StoreAutoShiftConfig::STATUS_NORMAL)
            ->first();

        return $config !== null;
    }

    /**
     * 获取自动交班配置
     */
    public function getConfig(int $departmentId, int $bindPlayerId)
    {
        return StoreAutoShiftConfig::query()
            ->where('department_id', $departmentId)
            ->where('bind_player_id', $bindPlayerId)
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
                ->where('bind_player_id', $data['bind_player_id'])
                ->first();

            if (!$config) {
                $config = new StoreAutoShiftConfig();
                $config->department_id = $data['department_id'];
                $config->bind_player_id = $data['bind_player_id'];
            }

            // 更新配置
            $config->is_enabled = $data['is_enabled'] ?? 0;
            $config->shift_mode = $data['shift_mode'] ?? StoreAutoShiftConfig::MODE_DAILY;
            $config->shift_time = $data['shift_time'] ?? '02:00:00';
            $config->shift_weekdays = $data['shift_weekdays'] ?? null;
            $config->shift_interval_hours = $data['shift_interval_hours'] ?? null;
            $config->auto_settlement = $data['auto_settlement'] ?? 1;
            $config->notify_on_failure = $data['notify_on_failure'] ?? 1;
            $config->notify_phones = $data['notify_phones'] ?? null;
            $config->status = StoreAutoShiftConfig::STATUS_NORMAL;

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
                'bind_player_id' => $data['bind_player_id'],
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
        // 验证交班时间格式
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $config->shift_time)) {
            return ['valid' => false, 'message' => '交班时间格式错误'];
        }

        // 验证每周模式
        if ($config->shift_mode == StoreAutoShiftConfig::MODE_WEEKLY) {
            if (empty($config->shift_weekdays)) {
                return ['valid' => false, 'message' => '每周模式必须选择至少一天'];
            }
            $weekdays = explode(',', $config->shift_weekdays);
            foreach ($weekdays as $day) {
                if (!in_array($day, [0, 1, 2, 3, 4, 5, 6])) {
                    return ['valid' => false, 'message' => '每周交班日期值无效'];
                }
            }
        }

        // 验证自定义周期
        if ($config->shift_mode == StoreAutoShiftConfig::MODE_CUSTOM) {
            if (empty($config->shift_interval_hours) || $config->shift_interval_hours < 1) {
                return ['valid' => false, 'message' => '自定义周期必须大于0小时'];
            }
            if ($config->shift_interval_hours > 168) {
                return ['valid' => false, 'message' => '自定义周期不能超过168小时（7天）'];
            }
        }

        // 验证通知手机号
        if ($config->notify_on_failure && !empty($config->notify_phones)) {
            $phones = explode(',', $config->notify_phones);
            foreach ($phones as $phone) {
                $phone = trim($phone);
                if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                    return ['valid' => false, 'message' => '手机号格式错误: ' . $phone];
                }
            }
        }

        return ['valid' => true];
    }

    /**
     * 计算下次交班时间
     */
    public function calculateNextShiftTime(StoreAutoShiftConfig $config): Carbon
    {
        $now = Carbon::now();
        $time = Carbon::parse($config->shift_time);

        switch ($config->shift_mode) {
            case StoreAutoShiftConfig::MODE_DAILY: // 每日
                $next = Carbon::today()->setTime($time->hour, $time->minute, $time->second);
                if ($next->lte($now)) {
                    $next->addDay();
                }
                return $next;

            case StoreAutoShiftConfig::MODE_WEEKLY: // 每周
                $weekdays = array_map('intval', explode(',', $config->shift_weekdays));
                sort($weekdays);
                $currentWeekday = $now->dayOfWeek;

                // 找到下一个交班日
                $nextWeekday = null;
                foreach ($weekdays as $day) {
                    if ($day > $currentWeekday) {
                        $nextWeekday = $day;
                        break;
                    } elseif ($day == $currentWeekday) {
                        // 今天有交班，检查时间是否已过
                        $todayShift = Carbon::today()->setTime($time->hour, $time->minute, $time->second);
                        if ($todayShift->gt($now)) {
                            $nextWeekday = $day;
                            break;
                        }
                    }
                }

                // 如果本周没有了，取下周第一个
                if ($nextWeekday === null) {
                    $nextWeekday = $weekdays[0];
                    $daysToAdd = 7 - $currentWeekday + $nextWeekday;
                } else {
                    $daysToAdd = $nextWeekday - $currentWeekday;
                }

                $next = Carbon::today()->addDays($daysToAdd)->setTime($time->hour, $time->minute, $time->second);
                return $next;

            case StoreAutoShiftConfig::MODE_CUSTOM: // 自定义周期
                $lastShift = $config->last_shift_time ? Carbon::parse($config->last_shift_time) : $now;
                return $lastShift->copy()->addHours($config->shift_interval_hours);

            default:
                return $now->addDay();
        }
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
                    ->where('bind_player_id', $config->bind_player_id)
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
                $config->bind_player_id,
                $startTime->toDateTimeString(),
                $endTime->toDateTimeString()
            );

            // 5. 创建交班记录
            $shiftRecord = new StoreAgentShiftHandoverRecord();
            $shiftRecord->department_id = $config->department_id;
            $shiftRecord->bind_player_id = $config->bind_player_id;
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
            $log->bind_player_id = $config->bind_player_id;
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
                $log->bind_player_id = $config->bind_player_id;
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

            // 发送告警通知
            if ($config->notify_on_failure && $config->notify_phones) {
                $this->sendFailureNotification($config, $e->getMessage());
            }

            return ['code' => 1, 'msg' => '自动交班失败: ' . $e->getMessage()];
        }
    }

    /**
     * 统计交班数据
     */
    private function calculateShiftStatistics(int $bindPlayerId, string $startTime, string $endTime): array
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
            ->where('player.department_id', function ($query) use ($bindPlayerId) {
                $query->select('department_id')
                    ->from('player')
                    ->where('id', $bindPlayerId)
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
     * 发送失败通知
     */
    private function sendFailureNotification(StoreAutoShiftConfig $config, string $errorMsg): void
    {
        // TODO: 实现短信/邮件/企业微信通知
        $message = sprintf(
            "【自动交班失败】\n店家ID: %d\n失败时间: %s\n错误信息: %s",
            $config->department_id,
            date('Y-m-d H:i:s'),
            $errorMsg
        );

        \Log::info('发送自动交班失败通知', [
            'config_id' => $config->id,
            'phones' => $config->notify_phones,
            'message' => $message
        ]);

        // 这里可以集成短信服务
        // $smsService = new SmsService();
        // $smsService->send($config->notify_phones, $message);
    }

    /**
     * 获取待执行的配置列表
     */
    public function getPendingConfigs(): array
    {
        return StoreAutoShiftConfig::query()
            ->where('is_enabled', 1)
            ->where('status', StoreAutoShiftConfig::STATUS_NORMAL)
            ->where('next_shift_time', '<=', Carbon::now())
            ->get()
            ->toArray();
    }

    /**
     * 获取执行统计
     */
    public function getExecutionStats(int $departmentId, int $bindPlayerId, int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();

        $logs = StoreAutoShiftLog::query()
            ->where('department_id', $departmentId)
            ->where('bind_player_id', $bindPlayerId)
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
