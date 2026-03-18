<?php

namespace app\service\machine;

use app\model\GameType;
use app\model\Machine;
use Exception;
use support\Cache;
use Webman\Push\PushException;

/**
 * 机台操作服务
 */
class MachineServices
{
    const CACHE_PREFIX = 'machine_tcp_action_cache_';
    const MACHINE_DATA_PREFIX = 'machine_tcp_data_cache_'; // 操作缓存前缀
    public $machine = null; // 机台缓存前缀
    public $cacheKey;
    public $cacheDataKey;
    public $cacheDataKeyArr;
    public $lang;
    public $machineInfo;

    /**
     * 创建机台服务
     * @param Machine $machine 机台
     * @param string $lang 语言
     * @return Jackpot|Slot|SongSlot|SongJackpot
     * @throws Exception
     */
    public static function createServices(Machine $machine, string $lang = 'zh_CN')
    {
        switch ($machine->type) {
            case GameType::TYPE_SLOT:
                switch ($machine->control_type) {
                    case Machine::CONTROL_TYPE_MEI:
                        return new Slot($machine, $lang);
                    case Machine::CONTROL_TYPE_SONG:
                        return new SongSlot($machine, $lang);
                    default:
                        throw new Exception('Invalid product type');
                }
            case GameType::TYPE_STEEL_BALL:
                switch ($machine->control_type) {
                    case Machine::CONTROL_TYPE_MEI:
                        return new Jackpot($machine, $lang);
                    case Machine::CONTROL_TYPE_SONG:
                        return new SongJackpot($machine, $lang);
                    default:
                        throw new Exception('Invalid product type');
                }
            default:
                throw new Exception('Invalid product type');
        }
    }

    /**
     * @param $attribute
     * @param $data
     * @param $machineId
     * @return string
     */
    public static function getAttributeDes($attribute, $data, $machineId): string
    {
        $result = str_replace(MachineServices::MACHINE_DATA_PREFIX . $machineId . '_', '', $attribute);
        $key = 'machine_data.' . $result;
        if ($result == 'action_time') {
            return trans($key, ['{data}' => millisecondsToTimeFormat($data)], 'machine_action');
        }
        if (in_array($result,
            [
                'auto',
                'move_point',
                'reward_status',
                'keeping',
                'gaming',
                'change_point_card_status',
            ])) {
            return trans($key, [
                '{data}' => $data == 1 ? trans('machine_status_yes', [], 'machine_action') : trans('machine_status_no',
                    [], 'machine_action')
            ], 'machine_action');
        }
        if (in_array($result, ['play_start_time', 'last_play_time', 'last_keep_at', 'last_point_at'])) {
            return trans($key, ['{data}' => $data > 0 ? date('Y-m-d H:i:s', $data) : ''], 'machine_action');
        }

        return trans($key, ['{data}' => $data], 'machine_action');
    }

    /**
     * 获取slot操作
     * @param $controlType
     * @return array
     */
    public static function getSlotAction($controlType): array
    {
        if ($controlType == Machine::CONTROL_TYPE_SONG) {
            return [
                SongSlot::ALL,
                SongSlot::MACHINE_OPEN,
                SongSlot::MACHINE_CLOSE,
                SongSlot::CHECK,
                SongSlot::WASH_ZERO,
                SongSlot::OPEN_ANY_POINT,
                SongSlot::OUT_ON,
                SongSlot::START,
                SongSlot::STOP_ONE,
                SongSlot::STOP_TWO,
                SongSlot::STOP_THREE,
                SongSlot::ALL_DOWN,
                SongSlot::READ_SCORE,
                SongSlot::READ_WIN,
                SongSlot::READ_BET,
                SongSlot::REWARD_SWITCH,
            ];
        }
        return [
            Slot::ALL,
            Slot::WASH_ZERO,
            Slot::WASH_POINT,
            Slot::OPEN_ANY_POINT,
            Slot::OUT_ON,
            Slot::OUT_OFF,
            Slot::START,
            Slot::OUTPUT . Slot::U1_PULSE,
            Slot::STOP_ONE,
            Slot::STOP_TWO,
            Slot::STOP_THREE,
            Slot::PRESSURE,
            Slot::OPEN_ONE,
            Slot::OPEN_TEN,
            Slot::MOVE_POINT_ON,
            Slot::MOVE_POINT_OFF,
            Slot::ALL_DOWN,
            Slot::OPEN_FIVE,
            Slot::OPEN_TESTING,
            Slot::READ_SCORE,
            Slot::READ_CREDIT2,
            Slot::READ_BET,
            Slot::READ_WIN,
            Slot::READ_RB,
            Slot::OPEN_TABLE,
            Slot::WASH_TABLE,
            Slot::REWARD_SWITCH,
            Slot::TESTING,
            Slot::GET_AUTO_STATUS,
        ];
    }

    /**
     * 获取slot操作
     * @param $controlType
     * @return array
     */
    public static function getChannelSlotAction($controlType): array
    {
        if ($controlType == Machine::CONTROL_TYPE_SONG) {
            return [
                SongSlot::ALL,
                SongSlot::OUT_ON,
                SongSlot::OUT_OFF,
                SongSlot::START,
                SongSlot::STOP_ONE,
                SongSlot::STOP_TWO,
                SongSlot::STOP_THREE,
            ];
        }
        return [
            Slot::ALL,
            Slot::OUT_ON,
            Slot::OUT_OFF,
            Slot::START,
            Slot::OUTPUT . Slot::U1_PULSE,
            Slot::STOP_ONE,
            Slot::STOP_TWO,
            Slot::STOP_THREE,
            Slot::PRESSURE,
            Slot::MOVE_POINT_ON,
            Slot::MOVE_POINT_OFF,
        ];
    }

    /**
     * 获取钢珠操作
     * @param $controlType
     * @return array
     */
    public static function getJackpotAction($controlType): array
    {
        if ($controlType == Machine::CONTROL_TYPE_SONG) {
            return [
                SongJackpot::ALL,
                SongJackpot::WASH_ZERO,
                SongJackpot::OPEN_ANY_POINT,
                SongJackpot::TURN_UP_ALL,
                SongJackpot::TURN_DOWN_ALL,
                SongJackpot::AUTO_UP_TURN,
                SongJackpot::POINT_TO_TURN,
                SongJackpot::SCORE_TO_POINT,
                SongJackpot::REWARD_SWITCH,
                SongJackpot::MACHINE_POINT,
                SongJackpot::MACHINE_SCORE,
                SongJackpot::MACHINE_TURN,
                SongJackpot::WIN_NUMBER,
                SongJackpot::CLEAR_LOG,
                SongJackpot::CHECK,
                SongJackpot::MACHINE_OPEN,
                SongJackpot::MACHINE_CLOSE,
                SongJackpot::PUSH_THREE,
                SongJackpot::PUSH_ONE,
            ];
        }
        return [
            Jackpot::ALL,
            Jackpot::WASH_ZERO,
            Jackpot::WASH_ZERO_REMAINDER,
            Jackpot::OPEN_ANY_POINT,
            Jackpot::TURN_UP_ALL,
            Jackpot::TURN_DOWN_ALL,
            Jackpot::AUTO_UP_TURN,
            Jackpot::TURN_TO_POINT,
            Jackpot::POINT_TO_TURN,
            Jackpot::SCORE_TO_POINT,
            Jackpot::REWARD_SWITCH . Jackpot::REWARD_SWITCH_OPT,
            Jackpot::OPEN_ONE,
            Jackpot::OPEN_TEN,
            Jackpot::RESET_READY_TURN,
            Jackpot::OP_3,
            Jackpot::TESTING,
            Jackpot::MACHINE_POINT,
            Jackpot::MACHINE_SCORE,
            Jackpot::MACHINE_TURN,
            Jackpot::WIN_NUMBER,
            Jackpot::READ_OPEN_POINT,
            Jackpot::READ_WASH_POINT,
            Jackpot::CLEAR_LOG,
            Jackpot::PUSH . Jackpot::PUSH_STOP,
            Jackpot::PUSH . Jackpot::PUSH_ONE,
            Jackpot::PUSH . Jackpot::PUSH_TWO,
            Jackpot::PUSH . Jackpot::PUSH_THREE,
        ];
    }

    /**
     * 获取钢珠操作
     * @param $controlType
     * @return array
     */
    public static function getChannelJackpotAction($controlType): array
    {
        if ($controlType == Machine::CONTROL_TYPE_SONG) {
            return [
                SongJackpot::ALL,
                SongJackpot::AUTO_UP_TURN,
                SongJackpot::MACHINE_POINT,
                SongJackpot::MACHINE_SCORE,
                SongJackpot::MACHINE_TURN,
                SongJackpot::WIN_NUMBER,
                SongJackpot::REWARD_SWITCH,
            ];
        }

        return [
            Jackpot::ALL,
            Jackpot::AUTO_UP_TURN,
            Jackpot::REWARD_SWITCH . Jackpot::REWARD_SWITCH_OPT,
            Jackpot::MACHINE_POINT,
            Jackpot::MACHINE_SCORE,
            Jackpot::MACHINE_TURN,
            Jackpot::WIN_NUMBER,
            Jackpot::READ_OPEN_POINT,
            Jackpot::READ_WASH_POINT,
        ];
    }

    /**
     * 发送设备当前状态消息
     * @param int $machineId
     * @param string $status
     * @throws PushException
     */
    public static function sendMachineNowStatusMessage(int $machineId, string $status = 'online'): void
    {
        sendSocketMessage('private-admin_group-admin-1-' . $machineId, [
            'msg_type' => 'machine_now_status',
            'id' => $machineId,
            'machine_status' => $status,
        ]);
    }

    /**
     * 发送设备当前信息
     * @param int $playerId
     * @param int $machineId
     * @param string $name
     * @param array $info
     * @throws PushException
     */
    public static function sendMachineNowInfoMessage(int $playerId, int $machineId, string $name, array $info): void
    {
        sendSocketMessage('player-' . $playerId . '-' . $machineId, [
            'msg_type' => 'machine_now_info',
            'id' => $machineId,
            'info' => $name,
            'machine_info' => $info,
        ]);
    }

    /**
     * 发送设备当前信息
     * @param int $departmentId
     * @param string $msgType
     * @param array $info
     * @throws PushException
     */
    public static function sendMachineRealTimeInformation(int $departmentId, string $msgType, array $info): void
    {
        $info['last_game_at'] = !empty($info['last_game_at']) ? $info['last_game_at'] : '';
        $info['last_point_at'] = !empty($info['last_point_at']) ? date('Y-m-d H:i:s', $info['last_point_at']) : '';
        $info['last_play_time'] = !empty($info['last_play_time']) ? date('Y-m-d H:i:s', $info['last_play_time']) : '';
        $info['play_start_time'] = !empty($info['play_start_time']) ? date('Y-m-d H:i:s',
            $info['play_start_time']) : '';
        switch ($info['type']) {
            case GameType::TYPE_SLOT:
                $info['player_pressure'] = ($info['bet'] ?? 0) - ($info['player_pressure'] ?? 0);
                $info['player_score'] = ($info['win'] ?? 0) - ($info['player_score'] ?? 0);
                break;
            case GameType::TYPE_STEEL_BALL:
                $info['player_win_number'] = $info['win_number'] - $info['player_win_number'];
                break;
        }
        $seconds = $info['keep_seconds'];
        if ($seconds > 3600) {
            $hours = intval($seconds / 3600);
            $time = $hours . ":" . gmstrftime('%M:%S', $seconds);
        } else {
            $time = gmstrftime('%H:%M:%S', $seconds);
        }
        $info['keep_seconds'] = $time;
        $givePoint = 0;
        $giveCache = getGivePoints($info['gaming_user_id'], $info['id']);
        if (!empty($giveCache)) {
            $givePoint = $giveCache['gift_point'] ?? 0;
        }
        $wash = floor((($info['point'] - $givePoint)) * ($info['odds_x'] ?? 1) / ($info['odds_y'] ?? 1));
        $info['wash'] = $wash > 0 ? $wash : 0;
        sendSocketMessage('machine-real-time-information-' . $departmentId, [
            'msg_type' => $msgType,
            'machine_info' => $info,
        ]);
    }

    /**
     * 设置操作缓存
     * @return mixed
     */
    public function getCache()
    {
        return Cache::get($this->cacheKey);
    }

    /**
     * 获取机台数据缓存
     * @return iterable
     */
    public function getMachineCache(): iterable
    {
        return Cache::getMultiple($this->cacheDataKeyArr);
    }
}