<?php

namespace app\service;

use app\model\Activity;
use app\model\ActivityContent;
use app\model\ActivityPhase;
use app\model\Machine;
use app\model\Notice;
use app\model\Player;
use app\model\PlayerActivityPhaseRecord;
use app\model\PlayerActivityRecord;
use app\model\SystemSetting;
use support\Cache;
use support\Log;
use Webman\Push\PushException;
use yzh52521\WebmanLock\Locker;

class ActivityServices
{
    /** @var Machine $machine */
    private $machine;

    /** @var Player $player */
    private $player;

    /** @var int $expireTime */
    private $expireTime = 60; // 每分钟发送一次活动领取消息

    /**
     * @param $machine
     * @param $player
     */
    public function __construct($machine, $player)
    {
        $this->machine = $machine;
        $this->player = $player;
    }

    /**
     * 添加玩家活动达成记录
     * @param $score
     * @return void
     * @throws PushException
     */
    public function playerActivityPhaseRecord($score): void
    {
        /** @var SystemSetting $setting */
        $setting = SystemSetting::where('feature', 'activity_open')->first();
        if ($setting->status == 0) {
            return;
        }
        $actionLockerKey = 'machine_activity_' . $this->player->id . '_' . $this->machine->id;
        $lock = Locker::lock($actionLockerKey, 2, true);
        if (!$lock->acquire()) {
            Log::error('playerActivityPhaseRecord -> 任务处理重复');
            return;
        }
        try {
            $this->addPlayerActivityRecord();
            $playerActivityRecordList = PlayerActivityRecord::where('machine_id', $this->machine->id)
                ->where('player_id', $this->player->id)
                ->where('status', PlayerActivityRecord::STATUS_BEGIN)
                ->orderBy('created_at', 'desc')
                ->get();
            $playerBonus = $this->getBonus();
            if (!empty($playerActivityRecordList)) {
                $activityMachine = [];
                /** @var PlayerActivityRecord $playerActivityRecord */
                foreach ($playerActivityRecordList as $playerActivityRecord) {
                    if (in_array($playerActivityRecord->activity_id . '_' . $playerActivityRecord->machine_id,
                        $activityMachine)) {
                        continue;
                    }
                    $activityMachine[] = $playerActivityRecord->activity_id . '_' . $playerActivityRecord->machine_id;
                    if ($playerActivityRecord->activity->status == 0 || !empty($playerActivityRecord->activity->deleted_at) || strtotime($playerActivityRecord->activity->end_time) < time()) {
                        $playerActivityRecord->status = PlayerActivityRecord::STATUS_FINISH;
                        $playerActivityRecord->finish_at = date('Y-m-d H:i:s');
                        $playerActivityRecord->save();
                        continue;
                    }
                    $playerActivityRecord->score = $score;

                    $activityPhaseList = $playerActivityRecord->activity->activity_phase->where('cate_id',
                        $this->machine->cate_id)->sortBy('condition', 4)->where('condition', '<=', $score)->all();
                    if (empty($activityPhaseList)) {
                        $playerActivityRecord->save();
                        continue;
                    }
                    /** @var ActivityPhase $activityPhase */
                    foreach ($activityPhaseList as $activityPhase) {
                        /** @var PlayerActivityPhaseRecord $playerActivityPhaseRecord */
                        $playerActivityPhaseRecord = PlayerActivityPhaseRecord::where('player_activity_record_id',
                            $playerActivityRecord->id)
                            ->where('activity_phase_id', $activityPhase->id)
                            ->where('condition', $activityPhase->condition)
                            ->first();
                        if (empty($playerActivityPhaseRecord)) {
                            $playerActivityPhaseRecord = new PlayerActivityPhaseRecord();
                            $playerActivityPhaseRecord->activity_id = $playerActivityRecord->activity_id;
                            $playerActivityPhaseRecord->cate_id = $playerActivityRecord->cate_id;
                            $playerActivityPhaseRecord->activity_phase_id = $activityPhase->id;
                            $playerActivityPhaseRecord->player_activity_record_id = $playerActivityRecord->id;
                            $playerActivityPhaseRecord->player_id = $this->player->id;
                            $playerActivityPhaseRecord->department_id = $this->player->department_id;
                            $playerActivityPhaseRecord->condition = $activityPhase->condition;
                            $playerActivityPhaseRecord->bonus = $activityPhase->bonus;
                            $playerActivityPhaseRecord->notice = $activityPhase->notice;
                            $playerActivityPhaseRecord->machine_id = $this->machine->id;
                            $playerActivityPhaseRecord->status = PlayerActivityPhaseRecord::STATUS_RECEIVED;
                            $playerActivityPhaseRecord->player_score = $score;
                            $playerActivityPhaseRecord->save();
                            $playerActivityRecord->bonus = bcadd($playerActivityRecord->bonus, $activityPhase->bonus);
                            sendSocketMessage('player-' . $this->player->id, [
                                'id' => $playerActivityPhaseRecord->id,
                                'msg_type' => 'player_activity_phase',
                                'player_id' => $this->player->id,
                                'machine_id' => $this->machine->id,
                                'type' => $this->machine->type,
                                'activity_id' => $activityPhase->activity_id,
                                'player_bonus' => $playerActivityRecord->bonus,
                                'activity_phase' => [
                                    'id' => $playerActivityPhaseRecord->id,
                                    'activity_phase_id' => $activityPhase->id,
                                    'activity_id' => $activityPhase->activity_id,
                                    'condition' => $activityPhase->condition,
                                    'bonus' => $activityPhase->bonus,
                                    'notice' => json_decode($activityPhase->notice, true),
                                ],
                            ]);

                            $playerBonus += $activityPhase->bonus;
                        }
                    }
                    $playerActivityRecord->save();
                }
            }
            if ($playerBonus > 0) {
                $this->setBonus($playerBonus);
            }
        } catch (\Exception $e) {
            Log::error('Error in playerActivityPhaseRecord: ' . $e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * 添加玩家活动记录
     * @return void
     */
    public function addPlayerActivityRecord()
    {
        /** @var SystemSetting $setting */
        $setting = SystemSetting::where('feature', 'activity_open')->first();
        if ($setting->status == 0) {
            return;
        }
        $nowTime = date('Y-m-d H:i:s');
        $cateId = $this->machine->cate_id;
        $activityList = Activity::whereRaw("FIND_IN_SET('{$cateId}', cate_id)")
            ->whereJsonContains('department_id', $this->player->department_id)
            ->where('start_time', '<=', $nowTime)
            ->where('end_time', '>=', $nowTime)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->get();
        if (!empty($activityList)) {
            /** @var Activity $activity */
            foreach ($activityList as $activity) {
                $playerActivityRecord = PlayerActivityRecord::where('activity_id', $activity->id)
                    ->where('machine_id', $this->machine->id)
                    ->where('player_id', $this->player->id)
                    ->where('status', PlayerActivityRecord::STATUS_BEGIN)
                    ->first();

                if (empty($playerActivityRecord)) {
                    $activityRecord = new PlayerActivityRecord();
                    $activityRecord->activity_id = $activity->id;
                    $activityRecord->cate_id = $this->machine->cate_id;
                    $activityRecord->machine_id = $this->machine->id;
                    $activityRecord->player_id = $this->player->id;
                    $activityRecord->department_id = $this->player->department_id;
                    $activityRecord->type = $this->machine->type;
                    $activityRecord->code = $this->machine->code;
                    $activityRecord->save();
                }
            }
        }
    }

    /**
     * 获取玩家奖励
     * @return int|mixed
     */
    protected function getBonus()
    {
        return Cache::get($this->playerActivityKey()) ?? 0;
    }

    /**
     * 玩家游戏缓存key
     * @return string
     */
    public function playerActivityKey(): string
    {
        return 'player_bonus_activity_key_' . $this->player->id . '_' . $this->machine->id;
    }

    /**
     * 缓存玩家活动奖励
     * @param $playerBonus
     * @return bool
     */
    protected function setBonus($playerBonus): bool
    {
        return Cache::set($this->playerActivityKey(), $playerBonus, 24 * 60 * 60 * 10);
    }

    /**
     * 获取玩家活动奖励
     * @return int
     */
    public function playerActivityBonus(): int
    {
        /** @var SystemSetting $setting */
        $setting = SystemSetting::where('feature', 'activity_open')->first();
        if ($setting->status == 0) {
            return 0;
        }

        return $this->getBonus();
    }

    /**
     * 玩家结束活动参与
     * @param bool $isResetBonus 是否重置奖励
     * @return string
     */
    public function playerFinishActivity(bool $isResetBonus = false): string
    {
        if ($isResetBonus) {
            $this->resetBonus();
        }
        return PlayerActivityRecord::where('machine_id', $this->machine->id)
            ->where('player_id', $this->player->id)
            ->update([
                'status' => PlayerActivityRecord::STATUS_FINISH,
                'finish_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 充值玩家活动奖励
     * @return bool
     */
    protected function resetBonus(): bool
    {
        return Cache::delete($this->playerActivityKey());
    }

    /**
     * 发送活动领取提醒
     * @return void
     * @throws PushException
     */
    public function machineActivity()
    {
        $unreceivedActivityList = $this->playerUnreceivedActivity();
        foreach ($unreceivedActivityList as $item) {
            $cacheKey = 'player-' . $item['player_id'] . '-activity-' . $item['activity_id'] . '-phase-' . $item['id'];
            if (!Cache::get($cacheKey)) {
                // 发送活动领奖提醒
                $sendData = [
                    'id' => $item['id'],
                    'msg_type' => 'player_activity_phase',
                    'player_id' => $item['player_id'],
                    'machine_id' => $item['machine_id'],
                    'type' => $item['type'],
                    'activity_id' => $item['activity_id'],
                    'player_bonus' => $item['player_bonus'],
                    'activity_phase' => $item['activity_phase'],
                ];
                sendSocketMessage('player-' . $item['player_id'], $sendData);
                Cache::set($cacheKey, $sendData, $this->expireTime);
            }
        }
    }

    /**
     * 玩家登录待领取奖励
     * @return array
     */
    public function playerUnreceivedActivity(): array
    {
        $list = [];
        /** @var SystemSetting $setting */
        $setting = SystemSetting::where('feature', 'activity_open')->first();
        if ($setting->status == 0) {
            return $list;
        }
        $nowTime = date('Y-m-d H:i:s');
        $playerActivityPhaseRecord = PlayerActivityPhaseRecord::where('status',
            PlayerActivityPhaseRecord::STATUS_UNRECEIVED)
            ->whereHas('activity', function ($query) use ($nowTime) {
                $query->where('start_time', '<=', $nowTime)
                    ->where('end_time', '>=', $nowTime)
                    ->where('status', 1)
                    ->whereNull('deleted_at');
            });
        if (!empty($this->player)) {
            $playerActivityPhaseRecord->where('player_id', $this->player->id);
        }
        $playerActivityPhaseRecordList = $playerActivityPhaseRecord->get();
        /** @var PlayerActivityPhaseRecord $item */
        foreach ($playerActivityPhaseRecordList as $item) {
            $list[] = [
                'id' => $item->id,
                'activity_id' => $item->activity_id,
                'player_id' => $item->player_id,
                'machine_id' => $item->machine_id,
                'type' => $item->machine->type,
                'player_score' => $item->player_activity_record->score,
                'player_bonus' => $item->player_activity_record->bonus,
                'activity_phase' => [
                    'condition' => $item->condition,
                    'bonus' => $item->bonus,
                    'notice' => $item->notice ? json_decode($item->notice, true) : [],
                ]
            ];
        }

        return $list;
    }

    /**
     * 发送待审核消息
     * @return void
     * @throws PushException
     */
    public function reviewedMessage()
    {
        $settingStatus = Cache::get('activity_open_setting');
        if (!$settingStatus) {
            $settingStatus = SystemSetting::where('feature', 'activity_open')->value('status');
            Cache::set('activity_open_setting', $settingStatus ?? 0, 60);
        }

        if (empty($settingStatus)) {
            return;
        }

        /** @var PlayerActivityPhaseRecord $playerActivityPhaseRecord */
        $playerActivityPhaseRecord = PlayerActivityPhaseRecord::where('status',
            PlayerActivityPhaseRecord::STATUS_RECEIVED)->first();
        if (!empty($playerActivityPhaseRecord)) {
            /** @var ActivityContent $activityContent */
            $activityContent = $playerActivityPhaseRecord->activity->activity_content()
                ->where('lang', $playerActivityPhaseRecord->player->channel->lang)
                ->first();
            $content = '活動獎勵待稽核，玩家' . (empty($playerActivityPhaseRecord->player->name) ? $playerActivityPhaseRecord->player->name : $playerActivityPhaseRecord->player->phone);
            $content .= ', 在機台: ' . $playerActivityPhaseRecord->machine->code;
            $content .= ' 達成活動: ' . ($activityContent->name ? $activityContent->name : '') . '的獎勵要求';
            $content .= ' 獎勵遊戲點: ' . $playerActivityPhaseRecord->bonus . '.';
            $notice = new Notice();
            $notice->department_id = $playerActivityPhaseRecord->player->department_id;
            $notice->player_id = $playerActivityPhaseRecord->player_id;
            $notice->source_id = $playerActivityPhaseRecord->id;
            $notice->type = Notice::TYPE_EXAMINE_ACTIVITY;
            $notice->receiver = Notice::RECEIVER_ADMIN;
            $notice->is_private = 0;
            $notice->title = '活動獎勵待稽核';
            $notice->content = $content;
            $notice->save();
            // 发送消息
            sendSocketMessage('private-admin_group-admin-1', [
                'msg_type' => 'player_examine_activity_bonus',
                'id' => $playerActivityPhaseRecord->id,
                'player_id' => $playerActivityPhaseRecord->player_id,
            ]);
            sendSocketMessage('private-admin_group-channel-' . $playerActivityPhaseRecord->player->department_id, [
                'msg_type' => 'player_examine_activity_bonus',
                'id' => $playerActivityPhaseRecord->id,
                'player_id' => $playerActivityPhaseRecord->player_id,
            ]);
        }
    }
}
