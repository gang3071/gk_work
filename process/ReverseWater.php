<?php

namespace process;

use app\model\Channel;
use app\model\ChannelPlatformReverseWater;
use app\model\Player;
use app\model\PlayerReverseWaterDetail;
use app\model\PlayGameRecord;
use Carbon\Carbon;
use support\Db;
use support\Log;
use support\Redis;
use Workerman\Crontab\Crontab;

class ReverseWater
{
    public function onWorkerStart()
    {
        // 每天1点执行一次
        new Crontab('00 1 * * *', function () {
            $lockKey = 'crontab:reverse_water:lock'; // 锁键名

            // 尝试获取锁
            if (!$this->lock($lockKey, 580)) {
                Log::channel('reverse_water')->info('反水任务正在其他进程执行，跳过');
                return;
            }

            //获取昨天的开始和结束时间
            ini_set('memory_limit', '512M');
            //获取所有开启电子游戏反水的渠道
            $yesterday = Carbon::yesterday();
            $startTime = $yesterday->copy()->startOfDay()->format('Y-m-d H:i:s');
            $endTime = $yesterday->copy()->endOfDay()->format('Y-m-d H:i:s');
            $now = Carbon::now()->format('H:i:s');
            // 构建渠道配置映射表
            $waterMap = [];
            $departmentIds = Channel::query()->where('reverse_water_status', 1)->pluck('department_id');

            ChannelPlatformReverseWater::query()
                ->whereIn('department_id', $departmentIds)
                ->where('status', 1)
                ->where('checkout_time', '<=', $now)
                ->each(function ($waterModel) use (&$waterMap) {
                    $waterMap[$waterModel->department_id][$waterModel->platform_id] = $waterModel;
                });

            if (empty($waterMap)) return;

            // 一次性获取所有需要处理的游戏记录
            $playRecords = PlayGameRecord::with('player:id,department_id,status_reverse_water')
                ->select([
                    'player_id',
                    'platform_id',
                    DB::raw('SUM(bet) as total_bet'),
                    DB::raw('SUM(diff) as total_diff'),
                    DB::raw('GROUP_CONCAT(id ORDER BY id) as record_ids')
                ])
                ->whereBetween('created_at', [$startTime, $endTime])
                ->where('is_reverse', 0)
                ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
                ->whereHas('player', function ($query) use ($waterMap) {
                    $query->whereIn('department_id', array_keys($waterMap))
                        ->where('status_reverse_water', 1);
                })
                ->groupBy('player_id', 'platform_id')
                ->get();

            // 按玩家+渠道+平台分组统计
            $groupData = [];
            /** @var PlayGameRecord $record */
            foreach ($playRecords as $record) {
                if (!$player = $record->player) continue;

                $key = "{$record->player_id}_{$record->platform_id}";

                $recordIds = explode(',', $record->record_ids ?? '');
                $validIds = array_filter($recordIds, function ($id) {
                    return is_numeric($id) && $id !== '';
                });

                if (!isset($groupData[$key])) {
                    $groupData[$key] = [
                        'player' => $player,
                        'waterModel' => $waterMap[$player->department_id][$record->platform_id] ?? null,
                        'total_bet' => (float)$record->total_bet,
                        'total_diff' => (float)$record->total_diff,
                        'record_ids' => $validIds
                    ];
                }
            }

            $insertData = [];
            $playGameIds = [];
            $time = Carbon::now();
            $date = Carbon::yesterday()->format('Y-m-d');

            foreach ($groupData as $item) {
                if (!$item['waterModel'] || $item['total_bet'] <= 0) continue;

                /** @var Player $player */
                $player = $item['player'];
                // 获取玩家等级反水比例
                $levelRatio = $player->national_promoter()
                    ->first()
                    ?->level_list()
                    ->first()->reverse_water ?? 0;

                // 获取平台反水比例
                $waterRatio = $item['waterModel']->setting()
                    ->where('point', '<=', $item['total_bet'])
                    ->orderBy('point', 'desc')
                    ->value('ratio') ?? 0;

                $reverseWater = (float)bcmul($item['total_bet'], ($levelRatio + $waterRatio) / 100, 2);

                $insertData[] = [
                    'admin_id' => 0,
                    'player_id' => $player->id,
                    'platform_id' => $item['waterModel']->platform_id,
                    'point' => $item['total_bet'],
                    'all_diff' => $item['total_diff'],
                    'date' => $date,
                    'reverse_water' => $reverseWater,
                    'level_ratio' => $levelRatio,
                    'created_at' => $time,
                    'platform_ratio' => $waterRatio,
                    'status' => 0
                ];

                $playGameIds = array_merge($playGameIds, $item['record_ids']);
            }
            Db::beginTransaction();
            try {
                PlayerReverseWaterDetail::query()->insert($insertData);
                PlayGameRecord::query()->whereIn('id', $playGameIds)->update(['is_reverse' => 1]);
                Db::commit();
            } catch (\Exception $e) {
                Log::channel('reverse_water')->error('反水错误: ' . $e->getMessage());
                Db::rollback();
            }
        });
    }

    /**
     * 获取分布式锁
     * @param string $key 锁键名
     * @param int $ttl 锁持有时间(秒)
     * @return bool 是否获取成功
     */
    private function lock(string $key, int $ttl = 60): bool
    {
        return Redis::set($key, 1, 'NX', 'EX', $ttl);
    }

    /**
     * 释放分布式锁
     */
    private function release(string $key): void
    {
        Redis::del($key);
    }
}
