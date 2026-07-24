<?php
/**
 * Here is your custom functions.
 */

use app\model\GameType;
use app\model\LevelList;
use app\model\Machine;
use app\model\MachineCategoryGiveRule;
use app\model\MachineKeepingLog;
use app\model\MachineKickLog;
use app\model\MachineMedia;
use app\model\MachineMediaPush;
use app\model\NationalProfitRecord;
use app\model\Notice;
use app\model\Player;
use app\model\PlayerDeliveryRecord;
use app\model\PlayerGameLog;
use app\model\PlayerGameRecord;
use app\model\PlayerGiftRecord;
use app\model\PlayerLotteryRecord;
use app\model\PlayerPlatformCash;
use app\model\PlayerPromoter;
use app\model\PlayerRechargeRecord;
use app\model\PlayerWithdrawRecord;
use app\model\PlayGameRecord;
use app\model\PromoterProfitRecord;
use app\model\PromoterProfitSettlementRecord;
use app\model\StoreSetting;
use app\model\SystemSetting;
use app\service\ActivityServices;
use app\service\LotteryServices;
use app\service\machine\Jackpot;
use app\service\machine\MachineServices;
use app\service\machine\Slot;
use app\service\MediaServer;
use GatewayWorker\Lib\Gateway;
use support\Cache;
use support\Db;
use support\Log;
use Webman\Push\Api;
use Webman\Push\PushException;
use Webman\RedisQueue\Client as queueClient;
use yzh52521\WebmanLock\Locker;

/**
 * 检查玩家游戏状态 5分钟没有使用机台玩家将被踢出(分数返还)
 * @return void
 * @throws Exception
 */
function machineKeepOutPlayer(): void
{
    $log = Log::channel('machine_keeping');
    //機台例行維護中
    try {
        if (machineMaintaining()) {
            $log->info('PlayOutMachine: 全站维护中');
            return;
        }
    } catch (\Exception $e) {
        // 数据库连接失败时不应中断整个流程，继续执行保留倒计时和踢人逻辑
        $log->warning('检查维护状态失败，继续执行保留倒计时', [
            'error' => $e->getMessage()
        ]);
    }
    /** @var SystemSetting $setting */
    try {
        $setting = SystemSetting::query()->where('feature', 'pending_minutes')->where('status', 1)->first();
        if (!$setting || $setting->num <= 0) {
            $settingMinutes = 2; // 默认2分钟进入保留状态
        } else {
            $settingMinutes = $setting->num;
        }
    } catch (\Exception $e) {
        // 数据库连接失败时使用默认值
        $log->warning('获取保留时长配置失败，使用默认值2分钟', ['error' => $e->getMessage()]);
        $settingMinutes = 2;
    }

    // 不扣保留时间设置
    $isFreeTime = false;
    /** @var SystemSetting $keepingSetting */
    try {
        $keepingSetting = SystemSetting::query()->where('feature', 'keeping_off')->where('status', 1)->first();
    } catch (\Exception $e) {
        $log->warning('获取免费保留配置失败', ['error' => $e->getMessage()]);
        $keepingSetting = null;
    }
    if (!empty($keepingSetting)) {
        $offStart = $keepingSetting['date_start'] ?? '';
        $offEnd = $keepingSetting['date_end'] ?? '';
        if (!empty($offStart) && !empty($offEnd)) {
            $dateStart = date('Y-m-d') . ' ' . $offStart;
            $dateEnd = date('Y-m-d') . ' ' . $offEnd;

            if ($dateStart > $dateEnd) {
                $dateStart = date('Y-m-d H:i:s', strtotime($dateStart . '-1 day'));
            }

            $now = time();
            if ($now >= strtotime($dateStart) && $now <= strtotime($dateEnd)) {
                $isFreeTime = true;
            }
        }
    }
    //遊戲中玩家
    try {
        $gamingMachines = Machine::query()
            ->where('gaming', 1)
            ->where('gaming_user_id', '!=', 0)
            ->orderBy('type')
            ->get();
    } catch (\Exception $e) {
        // 数据库连接失败时中止，避免后续操作异常
        $log->error('获取游戏中机台列表失败', ['error' => $e->getMessage()]);
        return;
    }
    /** @var Machine $machine */
    foreach ($gamingMachines as $machine) {
        try {
            $services = MachineServices::createServices($machine);

            // ✅ 统一从 Redis 读取 gaming_user_id（实时数据）
            $gamingUserId = $services->gaming_user_id ?? 0;

            if (Cache::has('machine_open_point' . $machine->id . '_' . $gamingUserId)) {
                continue;
            }

            // ⚠️ 数据一致性检查：如果 Redis gaming_user_id 为 0，说明状态异常
            if ($gamingUserId == 0) {
                $log->warning('PlayOutMachine: 机台gaming状态异常 - Redis gaming_user_id 为 0', [
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'db_gaming' => $machine->gaming,
                    'db_gaming_user_id' => $machine->gaming_user_id,
                    'redis_gaming_user_id' => $gamingUserId,
                ]);

                // 修复数据库状态（设为非游戏中）
                $machine->gaming = 0;
                $machine->gaming_user_id = 0;
                $machine->save();
                continue;
            }

            /** @var Player $player */
            $player = Player::query()->find($gamingUserId);

            // ⚠️ 数据一致性检查：如果玩家不存在，说明数据异常
            if (!$player) {
                $log->warning('PlayOutMachine: 机台gaming状态异常 - 玩家不存在', [
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'gaming_user_id' => $gamingUserId,
                ]);

                // 修复数据库和Redis状态（设为非游戏中）
                $machine->gaming = 0;
                $machine->gaming_user_id = 0;
                $machine->save();
                $services->gaming = 0;
                $services->gaming_user_id = 0;
                continue;
            }

            if ($services->has_lock == 1) {
                $log->info('PlayOutMachine: 机台锁定跳过' . $machine->code);
                continue;
            }
            if ($machine->maintaining == 1) {
                $services->last_play_time = time();
            }
            $minutes = $settingMinutes * 60;
            if ($machine->type == GameType::TYPE_SLOT && $services->reward_status == 1) {
                $minutes = $settingMinutes + (15 * 60);
            }
            if ($services->keeping == 0 && time() - $services->last_play_time > $minutes) {
                // ⚠️ C376 进入保留状态日志
                if ($machine->code === 'C376') {
                    $log->info('[C376-EnterKeeping] 玩家无操作，进入保留状态', [
                        'machine_id' => $machine->id,
                        'machine_code' => $machine->code,
                        'player_id' => $player->id,
                        'last_play_time' => $services->last_play_time,
                        'last_play_time_formatted' => date('Y-m-d H:i:s', $services->last_play_time),
                        'idle_seconds' => time() - $services->last_play_time,
                        'idle_threshold_seconds' => $minutes,
                        'now_turn' => $services->now_turn ?? 'N/A',
                        'win_number' => $services->win_number ?? 'N/A',
                        'bet' => $services->bet ?? 'N/A',
                        'reward_status' => $services->reward_status ?? 'N/A',
                    ]);
                }

                if ($machine->type == GameType::TYPE_SLOT && $machine->is_special == 0 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::OUT_OFF, 0, 'player', $player->id, 1);
                }
                $services->keeping = 1;
                $services->keeping_user_id = $gamingUserId;
                $services->last_keep_at = time();
                // 记录保留日志
                $machineKeepingLog = new MachineKeepingLog();
                $machineKeepingLog->player_id = $player->id;
                $machineKeepingLog->machine_id = $machine->id;
                $machineKeepingLog->machine_name = $machine->name;
                $machineKeepingLog->is_system = 1;
                $machineKeepingLog->department_id = $player->department_id;
                $machineKeepingLog->save();
                // 发送进入保留状态消息
                sendSocketMessage('player-' . $gamingUserId . '-' . $machine->id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $gamingUserId,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $services->keep_seconds,
                    'keeping' => $services->keeping
                ]);
                sendSocketMessage('player-' . $gamingUserId, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $gamingUserId,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $services->keep_seconds,
                    'keeping' => $services->keeping
                ]);
            }
            if ($services->keeping == 0) {
                $log->info('PlayOutMachine: 非保留状态跳过' . $machine->code);
                continue;
            }
            if ($isFreeTime && $services->keep_seconds > 1800) {
                $log->info('PlayOutMachine: 自由时间且时间大于1800秒跳过' . $machine->code);
                continue;
            }
            $keepSeconds = $services->keep_seconds;

            // ✅ 诊断日志：检查 last_play_time 和活动状态（仅 S326）
            if ($machine->code == 'S326') {
                $currentTime = time();
                $lastPlayTime = $services->last_play_time;
                $timeSinceLastPlay = $currentTime - $lastPlayTime;

                $log->debug('PlayOutMachine: 检查保留状态', [
                'machine_id' => $machine->id,
                'machine_code' => $machine->code,
                'player_id' => $gamingUserId,
                'keeping' => $services->keeping,
                'keep_seconds' => $keepSeconds,
                'last_play_time' => $lastPlayTime,
                'last_play_time_formatted' => date('Y-m-d H:i:s', $lastPlayTime),
                'current_time' => $currentTime,
                'time_since_last_play' => $timeSinceLastPlay,
                'now_turn' => $services->now_turn ?? 'N/A',
                'bet' => $services->bet ?? 'N/A',
                'reward_status' => $services->reward_status ?? 'N/A',
                ]);
            }

            if ($keepSeconds > 0) {
                if ($services->reward_status == 1) {
                    if ($machine->type == GameType::TYPE_STEEL_BALL) {
                        $log->info('PlayOutMachine: ' . $machine->code . '开奖中15分钟内不扣除保留时间');
                        continue;
                    }
                }
                $log->info('PlayOutMachine: 扣除保留时间', ['keeping_setting' => $keepingSetting, 'keep_seconds' => $keepSeconds]);
                $services->keep_seconds = max(bcsub($keepSeconds, 10), 0);
                // ✅ 修复：发送扣减后的新值
                $newKeepSeconds = $services->keep_seconds;
                sendSocketMessage('player-' . $gamingUserId . '-' . $machine->id, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $gamingUserId,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $newKeepSeconds,
                    'keeping' => $services->keeping
                ]);
                sendSocketMessage('player-' . $gamingUserId, [
                    'msg_type' => 'player_machine_keeping',
                    'player_id' => $gamingUserId,
                    'machine_id' => $machine->id,
                    'keep_seconds' => $newKeepSeconds,
                    'keeping' => $services->keeping
                ]);
            } else {
                // 保留时间为0时踢出玩家
                // ✅ 从 Redis 读取实时余额
                $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

                // ✅ 记录踢出前的机台状态
                $machineStatus = [
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'machine_type' => $machine->type,
                    'player_id' => $player->id,
                    'player_uuid' => $player->uuid,
                    'before_balance' => $beforeGameAmount,
                    'kick_reason' => '保留时间耗尽（系统自动踢出）',  // ✅ 踢出原因
                    'keep_seconds' => $services->keep_seconds,
                    'keeping_duration' => time() - $services->last_keep_at,  // 保留了多久
                ];

                // ⚠️ C376 详细踢出日志
                if ($machine->code === 'C376') {
                    $log->info('[C376-KickOut] 准备踢出玩家 - 详细状态', array_merge($machineStatus, [
                        'last_play_time' => $services->last_play_time,
                        'last_play_time_formatted' => date('Y-m-d H:i:s', $services->last_play_time),
                        'last_keep_at_formatted' => date('Y-m-d H:i:s', $services->last_keep_at),
                        'now_turn' => $services->now_turn ?? 'N/A',
                        'win_number' => $services->win_number ?? 'N/A',
                        'bet' => $services->bet ?? 'N/A',
                        'point' => $services->point ?? 'N/A',
                        'reward_status' => $services->reward_status ?? 'N/A',
                        'bb_status' => $services->bb_status ?? 'N/A',
                        'rush_status' => $services->rush_status ?? 'N/A',
                        'auto' => $services->auto ?? 'N/A',
                        'time_since_last_play' => time() - $services->last_play_time,
                    ]));
                } else {
                    $log->info('PlayOutMachine: 准备踢出玩家', $machineStatus);
                }

                try {
                    $washResult = machineWash($player, $machine, 'leave', 1);

                    //寫入踢人log
                    $afterGameAmount = \app\service\WalletService::getBalance($player->id);
                    $wash_point = abs($afterGameAmount - $beforeGameAmount);

                    // ⚠️ 异常检测：退分为0时记录警告
                    if ($wash_point == 0) {
                        if ($machine->code === 'C376') {
                            $log->warning('[C376-KickOut] 踢出玩家但退分为0，请检查硬件通信', array_merge($machineStatus, [
                                'after_balance' => $afterGameAmount,
                                'wash_point' => $wash_point,
                                'wash_result' => $washResult,
                            ]));
                        } else {
                            $log->warning('PlayOutMachine: 踢出玩家但退分为0，请检查硬件通信', array_merge($machineStatus, [
                                'after_balance' => $afterGameAmount,
                                'wash_point' => $wash_point,
                                'wash_result' => $washResult,
                            ]));
                        }
                    } else {
                        if ($machine->code === 'C376') {
                            $log->info('[C376-KickOut] 踢出成功并退分', [
                                'machine_id' => $machine->id,
                                'machine_code' => $machine->code,
                                'player_id' => $player->id,
                                'kick_reason' => '保留时间耗尽（系统自动踢出）',
                                'wash_point' => $wash_point,
                                'before_balance' => $beforeGameAmount,
                                'after_balance' => $afterGameAmount,
                            ]);
                        } else {
                            $log->info('PlayOutMachine: 踢出成功并退分', [
                                'machine_id' => $machine->id,
                                'player_id' => $player->id,
                                'kick_reason' => '保留时间耗尽（系统自动踢出）',
                                'wash_point' => $wash_point,
                                'before_balance' => $beforeGameAmount,
                                'after_balance' => $afterGameAmount,
                            ]);
                        }
                    }

                    $machineKickLog = new MachineKickLog;
                    $machineKickLog->player_id = $player->id;
                    $machineKickLog->machine_id = $machine->id;
                    $machineKickLog->platform_id = PlayerPlatformCash::PLATFORM_SELF;
                    $machineKickLog->wash_point = $wash_point;
                    $machineKickLog->before_game_amount = $beforeGameAmount;
                    $machineKickLog->after_game_amount = $afterGameAmount;
                    $machineKickLog->save();

                    // 更新保留日志
                    updateKeepingLog($machine->id, $player->id);

                    // 发送踢人消息
                    sendSocketMessage('player-' . $player->id . '-' . $machine->id, [
                        'msg_type' => 'kick_out',
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->name,
                        'machine_code' => $machine->code,
                        'wash_point' => $wash_point,
                        'before_game_amount' => $beforeGameAmount,
                        'after_game_amount' => $afterGameAmount
                    ]);
                    // 发送踢人消息
                    sendSocketMessage('player-' . $player->id, [
                        'msg_type' => 'kick_out',
                        'machine_id' => $machine->id,
                        'machine_name' => $machine->name,
                        'machine_code' => $machine->code,
                        'wash_point' => $wash_point,
                        'before_game_amount' => $beforeGameAmount,
                        'after_game_amount' => $afterGameAmount
                    ]);
                    sendSocketMessage('player-' . $player->id, [
                        'msg_type' => 'player_machine_keeping',
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                        'keep_seconds' => '0',
                        'keeping' => '0'
                    ]);

                    // 清理赠点缓存
                    Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);

                } catch (Exception $washException) {
                    // ❌ 退分失败，记录详细错误
                    $log->error('PlayOutMachine: 踢出玩家时退分失败', array_merge($machineStatus, [
                        'kick_reason' => '保留时间耗尽（系统自动踢出）',
                        'error' => $washException->getMessage(),
                        'trace' => $washException->getTraceAsString(),
                    ]));
                    // 重新抛出异常，让外层catch捕获
                    throw $washException;
                }
            }
        } catch (Exception $e) {
            $log->error('PlayOutMachine: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
    }
}

/**
 * 生成唯一单号
 * @return string
 */
function createOrderNo(): string
{

    $yCode = [
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'Q',
        'R',
        'S',
        'T',
        'U',
        'V',
        'W',
        'X',
        'Y',
        'Z'
    ];
    return $yCode[intval(date('Y')) - 2011] . strtoupper(dechex(date('m'))) . date('d') . substr(time(),
            -5) . substr(microtime(), 2, 5) . sprintf('%02d', rand(0, 99));
}

/**
 * 发送socket消息
 * @param $channels
 * @param $content
 * @param string $form
 * @return bool|string
 */
function sendSocketMessage($channels, $content, string $form = 'system'): bool|string
{
    try {
        // 直接读取 .env 配置，连接到 gk_api 的推送服务
        $api = new Api(
            env('PUSH_API_URL', 'http://10.140.0.6:3232'),
            env('PUSH_APP_KEY', '20f94408fc4c52845f162e92a253c7a3'),
            env('PUSH_APP_SECRET', '3151f8648a6ccd9d4515386f34127e28')
        );
        return $api->trigger($channels, 'message', [
            'from_uid' => $form,
            'content' => json_encode($content)
        ]);
    } catch (Exception $e) {
        Log::error('sendSocketMessage: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return false;
    }
}

/**
 * 获取增点缓存
 * @param $playerId
 * @param $machineId
 * @return mixed
 */
function getGivePoints($playerId, $machineId): mixed
{
    return Cache::get('gift_cache_' . $machineId . '_' . $playerId);
}

/**
 * 反转位（用于 CRC8 计算）
 * @param $num
 * @param $width
 * @return void
 */
function reflect_bits(&$num, $width): void
{
    $ref = 0;

    for ($i = 0; $i < $width; $i++) {
        $bit = ($num >> $i) & 0b1;
        $bit = ($bit << (($width - 1) - $i));
        $ref = $ref | $bit;
    }

    $num = $ref;
}

/**
 * crc8Maxim检查
 * @param $str
 * @param $polynomial
 * @param $ini
 * @param $xor
 * @param bool $ref_in
 * @param bool $ref_out
 * @param bool $has_fill
 * @return string
 * @throws Exception
 */
function crc8(
    $str,
    $polynomial,
    $ini,
    $xor,
    bool $ref_in = true,
    bool $ref_out = true,
    bool $has_fill = true
): string
{
    if (!is_scalar($str)) {
        throw new exception(
            "Variable for CRC calculation must be a scalar."
        );
    }
    $crc = $ini;
    for ($i = 0; $i < strlen($str); $i++) {
        $byte = ord($str[$i]);

        if ($ref_in) {
            reflect_bits($byte, 8);
        }
        $crc ^= $byte;
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x80) {
                $crc = (($crc << 1) & 0xff) ^ $polynomial;
            } else {
                $crc = ($crc << 1) & 0xff;
            }
        }
    }

    $result = ($crc ^ $xor) & 0xff;

    if ($ref_out) {
        reflect_bits($result, 8);
    }
    $result = sprintf("%02X", $result);

    if ($has_fill) {
        $hex = '';
        for ($i = strlen($result) - 1; $i >= 0; $i--) {
            $hex .= sprintf("%02X", hexdec($result[$i]));
        }
        return $hex;
    }

    return $result;
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function encodeData($data): string
{
    $dataStr = sprintf("%06X", $data);
    if (strlen($dataStr) > 6) {
        throw new Exception(trans('data_error', [], 'message'));
    }
    $dataStr = strrev($dataStr);
    $paddedStr = "";
    foreach (str_split($dataStr) as $char) {
        $paddedStr .= str_pad($char, 2, '0', STR_PAD_LEFT);
    }
    return str_pad($paddedStr, 12, '0');
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function jackpotEncodeData($data): string
{
    $dataStr = sprintf("%06X", $data);
    if (strlen($dataStr) > 6) {
        throw new Exception(trans('data_error', [], 'message'));
    }
    return substr($dataStr, 4, 2) . substr($dataStr, 2, 2) . substr($dataStr, 0, 2);
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function encodeDataXor55($data): string
{
    $cmd = sprintf("%06X", $data);
    if (strlen($cmd) > 6) {
        throw new Exception(trans('data_error', [], 'message'));
    }
    $result = intval(hexdec(substr($cmd, 4, 2))) ^ intval(hexdec(substr($cmd, 2, 2))) ^ intval(hexdec(substr($cmd,
            0,
            2))) ^ 0x55;
    $result = sprintf("%02X", $result);
    $hex = "";
    for ($i = strlen($result) - 1; $i >= 0; $i--) {
        $hex .= sprintf("%02X", hexdec($result[$i]));
    }
    return $hex;
}

/**
 * 数据位只能有3byte
 * @param $data
 * @return string
 * @throws Exception
 */
function jackpotEncodeDataXor55($data): string
{
    $cmd = sprintf("%06X", $data);
    if (strlen($cmd) > 6) {
        throw new Exception(trans('data_error', [], 'message'));
    }
    $result = intval(hexdec(substr($cmd, 0, 2))) ^ intval(hexdec(substr($cmd, 2, 2))) ^ intval(hexdec(substr($cmd,
            4,
            2))) ^ 0x55;
    return sprintf("%02X", $result);
}

/**
 * 检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkCRC8(string $data): bool
{
    $str = substr($data, 0, 28);
    $crc8 = substr($data, 28, 4);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00)) {
        throw new Exception(trans('crc8_check_failed', [], 'message') . ': ' . $crc8 . ' vs ' . crc8(hex2bin($str), 0x31, 0x00, 0x00));
    }

    return true;
}

/**
 * slot检查Xor55
 * @param string $msg
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkSlotXor55(string $msg, string $data): bool
{
    $fun = substr($msg, 2, 2);
    if ($fun == Slot::MACHINE_BUSY) {
        return true;
    }
    $xor55 = substr($msg, 20, 4);
    if ($xor55 !== encodeDataXor55($data)) {
        throw new Exception(trans('xor55_check_failed', [], 'message'));
    }

    return true;
}

/**
 * slot检查Xor55
 * @param string $msg
 * @param string $data
 * @return bool
 * @throws Exception
 */
function checkJackpotXor55(string $msg, string $data): bool
{
    $fun = substr($msg, 2, 2);
    if ($fun == '2B') {
        return true;
    }
    $xor55 = substr($msg, 14, 2);
    if ($xor55 !== jackpotEncodeDataXor55($data)) {
        throw new Exception(trans('xor55_check_failed', [], 'message'));
    }

    return true;
}

/**
 * 检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function jackPotCheckCRC8(string $data): bool
{
    $str = substr($data, 0, 28);
    $crc8 = substr($data, 28, 2);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00, true, true, false)) {
        throw new Exception(trans('crc8_check_failed', [], 'message'));
    }

    return true;
}

/**
 * 解码数据位
 * @param $msg
 * @return string
 */
function decodeData($msg): string
{
    $str = substr($msg, 8, 12);
    $data2HI = substr(substr($str, 10, 2), 1, 1);
    $data2LO = substr(substr($str, 8, 2), 1, 1);

    $data1HI = substr(substr($str, 6, 2), 1, 1);
    $data1LO = substr(substr($str, 4, 2), 1, 1);

    $data0HI = substr(substr($str, 2, 2), 1, 1);
    $data0LO = substr(substr($str, 0, 2), 1, 1);

    $input = ltrim($data2HI . $data2LO . $data1HI . $data1LO . $data0HI . $data0LO, '0');
    return intval(hexdec($input));
}

/**
 * 解码数据位
 * @param $msg
 * @return string
 */
function jackpotDecodeData($msg): string
{
    $str = substr($msg, 8, 6);

    $data0 = substr($str, 4, 2);
    $data1 = substr($str, 2, 2);
    $data2 = substr($str, 0, 2);

    $input = ltrim($data0 . $data1 . $data2, '0');
    return intval(hexdec($input));
}

/**
 * slot自动卡检查crc8
 * @param string $data
 * @return bool
 * @throws Exception
 */
function slotCheckCRC8(string $data): bool
{
    $str = substr($data, 0, 12);
    $crc8 = substr($data, 12, 2);
    if ($crc8 !== crc8(hex2bin($str), 0x31, 0x00, 0x00, true, true, false)) {
        throw new Exception(trans('crc8_check_failed', [], 'message'));
    }
    return true;
}

/**
 * 解码机台状态
 * @param $data
 * @return string
 */
function decodeStatus($data): string
{
    $decoded_stat = hexdec($data);
    return sprintf("%08b", $decoded_stat);
}

/**
 * 解码机台状态
 * @param Machine $machine
 * @param $type
 * @param int $playerId
 */
function sendMachineException(Machine $machine, $type, int $playerId = 0): void
{
    $notice = new Notice();
    $notice->department_id = 1;
    $notice->player_id = 0;
    $notice->source_id = $machine->id;
    $notice->receiver = Notice::RECEIVER_ADMIN;
    $notice->is_private = 0;
    switch ($type) {
        case Notice::TYPE_MACHINE_BET:
            $content = '斯洛';
            $content .= '機台編號為: ' . $machine->code . ', 發生bet（壓分）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台bet（壓分）异常通知';
            $notice->type = Notice::TYPE_MACHINE_BET;
            $notice->save();
            $msgType = 'machine_bet_error';
            break;
        case Notice::TYPE_MACHINE_WIN:
            $content = '斯洛';
            $content .= '機台編號為: ' . $machine->code . ', 發生win（得分）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台win（得分）异常通知';
            $notice->type = Notice::TYPE_MACHINE_WIN;
            $notice->save();
            $msgType = 'machine_win_error';
            break;
        case Notice::TYPE_MACHINE_WIN_NUMBER:
            $content = '钢珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生中洞兑奖次数（压转）數據异常，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台中洞兑奖次数（压转）异常通知';
            $notice->type = Notice::TYPE_MACHINE_WIN_NUMBER;
            $notice->save();
            $msgType = 'machine_win_error';
            break;
        case Notice::TYPE_MACHINE:
            $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生异常離線，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台離線通知';
            $notice->type = Notice::TYPE_MACHINE;
            $notice->save();
            $msgType = 'machine_online';
            break;
        case Notice::TYPE_MACHINE_LOCK:
            $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
            $content .= '機台編號為: ' . $machine->code . ', 發生异常鎖定，請聯系設備管理員處理！';
            $notice->content = $content;
            $notice->title = '機台鎖定通知';
            $notice->type = Notice::TYPE_MACHINE_LOCK;
            $notice->save();
            $msgType = 'machine_lock';
            if (!empty($playerId)) {
                /** @var Player $player */
                $player = Player::query()->find($playerId);
                sendSocketMessage('private-admin_group-channel-' . $player->department_id, [
                    'msg_type' => 'machine_lock',
                    'id' => $machine->id,
                    'player_id' => $player->id,
                ]);
                $content = $machine->type == GameType::TYPE_SLOT ? '斯洛' : '鋼珠';
                $content .= '機台編號為: ' . $machine->code . ', 發生异常鎖定';
                $content .= '當前使用玩家為: ' . $player->uuid . ', 發生异常鎖定，請聯系設備管理員處理！';
                $notice = new Notice();
                $notice->department_id = $player->department_id;
                $notice->player_id = $player->id;
                $notice->source_id = $machine->id;
                $notice->type = Notice::TYPE_MACHINE_LOCK;
                $notice->receiver = Notice::RECEIVER_DEPARTMENT;
                $notice->is_private = 0;
                $notice->title = '機台鎖定通知';
                $notice->content = $content;
                $notice->save();
            }
            break;
        default:
            return;
    }
    sendSocketMessage('private-admin_group-admin-1', [
        'msg_type' => $msgType,
        'id' => $machine->id,
    ]);
}

/**
 * 获取毫秒级时间戳
 * @return float
 */
function getMillisecond(): float
{
    [$t1, $t2] = explode(' ', microtime());
    return (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000000);
}

/**
 * 毫秒转时间戳
 * @param $millisecond
 * @return string
 */
function millisecondsToTimeFormat($millisecond): string
{
    $seconds = floor($millisecond / 1000000); // 将毫秒转换为秒

    $date = new DateTime();
    $date->setTimestamp($seconds);
    return $date->format('Y-m-d H:i:s');
}

//终止机台录像
/**
 * 生成随机1位字符串
 * @param int $length
 * @return string
 */
function generateRandomString(int $length = 1): string
{
    // 定义字符集
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    // 生成随机字符串
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
    }

    return $randomString;
}

/**
 *  获取推流地址
 *  如果不传key和过期时间，将返回不含防盗链的url
 * @param $machineCode
 * @param string $pushDomain
 * @param string $pushKey
 * @return array
 */
function getPushUrl($machineCode, string $pushDomain = '', string $pushKey = ''): array
{
    $pushUrl = '';
    $endpointServiceId = uniqid();
    if (!empty($machineCode) && !empty($pushDomain)) {
        $name = $machineCode . '_' . $endpointServiceId;
        if (!empty($pushKey)) {
            $time = date('Y-m-d H:i:s'); // 获取当前时间
            $timePlus24Hours = date('Y-m-d H:i:s', strtotime($time) + 24 * 60 * 60 * 30 * 24);
            $txTime = strtoupper(base_convert(strtotime($timePlus24Hours), 10, 16));
            $txSecret = md5($pushKey . $name . $txTime);
            $ext_str = "?" . http_build_query(array(
                    "txSecret" => $txSecret,
                    "txTime" => $txTime
                ));
        }
        $pushUrl = [
            'rtmp_url' => "rtmp://" . $pushDomain . "/live/" . $name . ($ext_str ?? ""),
            'expiration_date' => $timePlus24Hours ?? '',
            'endpoint_service_id' => $endpointServiceId,
            'machine_code' => $machineCode,
        ];
    }

    return $pushUrl;
}

/**
 * 清理媒体流
 * @return void
 */
function mediaClear(): void
{
    MachineMedia::query()
        ->whereHas('machine', function ($query) {
            $query->where('status', 1)->whereNull('deleted_at');
        })->chunk(100, function ($machineMediaList) {
            /** @var MachineMedia $machineMedia */
            foreach ($machineMediaList as $machineMedia) {
                $mediaServer = new MediaServer($machineMedia->push_ip, $machineMedia->media_app);
                try {
                    $endpointServiceId = [];
                    $streamInfo = $mediaServer->getBroadcasts($machineMedia->stream_name);
                    if (!empty($streamInfo['endPointList'])) {
                        foreach ($streamInfo['endPointList'] as $endPoint) {
                            if (!MachineMediaPush::query()->where('endpoint_service_id',
                                $endPoint['endpointServiceId'])->exists()) {
                                $mediaServer->deleteRtmpEndpoint($endPoint['endpointServiceId'],
                                    $machineMedia->stream_name);
                            }
                            $endpointServiceId[] = $endPoint['endpointServiceId'];
                        }
                    }
                    $mediaServer->log->error('MediaClear', [
                        'stream_info' => $streamInfo,
                        'endpoint_service_id' => $endpointServiceId,
                        'machine_code' => $machineMedia->machine->code
                    ]);
                } catch (Exception $e) {
                    $mediaServer->log->error('MediaClear: ' . $e->getMessage(), [
                        'machine_code' => $machineMedia->machine->code,
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        });
}

/**
 * 机台维护检查
 * @return bool
 */
function machineMaintaining(): bool
{
    //每周機台維護時段
    /** @var SystemSetting $setting */
    $setting = SystemSetting::query()->where('feature', 'machine_maintain')->first();
    if ($setting && $setting->status != 0) {
        $week = $setting->num;
        $time_start = $setting->date_start;
        $time_end = $setting->date_end;
        $today_week = date('w');
        if ($today_week == '0') {
            $today_week = '7';
        }
        //判斷星期是否一樣
        if ($week != $today_week) {
            return false;
        }
        if (!empty($time_start) && !empty($time_end)) {
            $date_start = date('Y-m-d') . ' ' . $time_start;
            $date_end = date('Y-m-d') . ' ' . $time_end;
            $now = time();
            if ($now >= strtotime($date_start) && $now <= strtotime($date_end)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * 更新保留日志
 * @param $machineId
 * @param $playerId
 * @return void
 */
function updateKeepingLog($machineId, $playerId): void
{
    /** @var MachineKeepingLog $machineKeepingLog */
    $machineKeepingLog = MachineKeepingLog::query()->where([
        'machine_id' => $machineId,
        'player_id' => $playerId
    ])->where('status', MachineKeepingLog::STATUS_STAR)->first();
    if ($machineKeepingLog) {
        // 更新保留日志
        $machineKeepingLog->keep_seconds = time() - strtotime($machineKeepingLog->created_at);
        $machineKeepingLog->status = MachineKeepingLog::STATUS_END;
        $machineKeepingLog->save();
    }
}

/**
 * 执行推广员分润结算
 * @param $id
 * @param int $userId
 * @param string $userName
 * @return void
 * @throws Exception
 */
function doSettlement($id, int $userId = 0, string $userName = ''): void
{
    /** @var PlayerPromoter $playerPromoter */
    $playerPromoter = PlayerPromoter::query()->where('player_id', $id)->first();
    if (empty($playerPromoter)) {
        throw new Exception(trans('profit_amount_not_found', [], 'message'));
    }
    if ($playerPromoter->status == 0) {
        throw new Exception(trans('player_promoter_has_disable', [], 'message'));
    }
    if (!isset($playerPromoter->profit_amount)) {
        throw new Exception(trans('profit_amount_not_found', [], 'message'));
    }
    $profitAmount = PromoterProfitRecord::query()->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
        ->where('promoter_player_id', $id)
        ->first([
            DB::raw('SUM(`withdraw_amount`) as total_withdraw_amount'),
            DB::raw('SUM(`recharge_amount`) as total_recharge_amount'),
            DB::raw('SUM(`commission`) as total_commission_amount'),
            DB::raw('SUM(`bonus_amount`) as total_bonus_amount'),
            DB::raw('SUM(`admin_deduct_amount`) as total_admin_deduct_amount'),
            DB::raw('SUM(`admin_add_amount`) as total_admin_add_amount'),
            DB::raw('SUM(`present_amount`) as total_present_amount'),
            DB::raw('SUM(`machine_up_amount`) as total_machine_up_amount'),
            DB::raw('SUM(`machine_down_amount`) as total_machine_down_amount'),
            DB::raw('SUM(`lottery_amount`) as total_lottery_amount'),
            DB::raw('SUM(`profit_amount`) as total_profit_amount'),
            DB::raw('SUM(`player_profit_amount`) as total_player_profit_amount'),
            DB::raw('SUM(`game_amount`) as total_game_amount'),
        ])
        ->toArray();

    DB::beginTransaction();
    try {
        $promoterProfitSettlementRecord = new PromoterProfitSettlementRecord();
        $promoterProfitSettlementRecord->department_id = $playerPromoter->player->department_id;
        $promoterProfitSettlementRecord->promoter_player_id = $playerPromoter->player_id;
        $promoterProfitSettlementRecord->total_withdraw_amount = $profitAmount['total_withdraw_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_recharge_amount = $profitAmount['total_recharge_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_commission_amount = $profitAmount['total_commission_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_bonus_amount = $profitAmount['total_bonus_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_admin_deduct_amount = $profitAmount['total_admin_deduct_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_admin_add_amount = $profitAmount['total_admin_add_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_present_amount = $profitAmount['total_present_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_machine_up_amount = $profitAmount['total_machine_up_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_machine_down_amount = $profitAmount['total_machine_down_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_lottery_amount = $profitAmount['total_lottery_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_profit_amount = $profitAmount['total_profit_amount'];
        $promoterProfitSettlementRecord->total_player_profit_amount = $profitAmount['total_player_profit_amount'] ?? 0;
        $promoterProfitSettlementRecord->total_game_amount = $profitAmount['total_game_amount'] ?? 0;
        $promoterProfitSettlementRecord->last_profit_amount = $playerPromoter->last_profit_amount;
        $promoterProfitSettlementRecord->adjust_amount = $playerPromoter->adjust_amount;
        $promoterProfitSettlementRecord->type = PromoterProfitSettlementRecord::TYPE_SETTLEMENT;
        $promoterProfitSettlementRecord->tradeno = createOrderNo();
        $promoterProfitSettlementRecord->user_id = $userId;
        $promoterProfitSettlementRecord->user_name = $userName;
        $settlement = $amount = bcsub(bcadd($promoterProfitSettlementRecord->total_profit_amount,
            $promoterProfitSettlementRecord->adjust_amount, 2),
            $promoterProfitSettlementRecord->total_commission_amount, 2);
        if ($amount > 0) {
            if ($playerPromoter->settlement_amount < 0) {
                $diffAmount = bcadd($amount, $playerPromoter->settlement_amount, 2);
                $settlement = max($diffAmount, 0);
            }
        }
        $promoterProfitSettlementRecord->actual_amount = $settlement;
        $promoterProfitSettlementRecord->save();
        // 更新结算报表
        PromoterProfitRecord::query()->where('status', PromoterProfitRecord::STATUS_UNCOMPLETED)
            ->where('promoter_player_id', $id)
            ->update([
                'status' => PromoterProfitRecord::STATUS_COMPLETED,
                'settlement_time' => date('Y-m-d H:i:s'),
                'settlement_tradeno' => $promoterProfitSettlementRecord->tradeno,
                'settlement_id' => $promoterProfitSettlementRecord->id,
            ]);
        // 结算后这些数据清零
        $playerPromoter->profit_amount = 0;
        $playerPromoter->player_profit_amount = 0;
        $playerPromoter->team_recharge_total_amount = 0;
        $playerPromoter->total_commission = 0;
        $playerPromoter->team_withdraw_total_amount = 0;
        $playerPromoter->adjust_amount = 0;
        // 更新数据
        $playerPromoter->team_profit_amount = bcsub($playerPromoter->team_profit_amount,
            $promoterProfitSettlementRecord->total_profit_amount, 2);
        $playerPromoter->last_profit_amount = $settlement;
        $playerPromoter->settlement_amount = bcadd($playerPromoter->settlement_amount, $amount, 2);
        $playerPromoter->team_settlement_amount = bcadd($playerPromoter->team_settlement_amount,
            $promoterProfitSettlementRecord->total_profit_amount, 2);
        $playerPromoter->last_settlement_time = date('Y-m-d', strtotime('-1 day'));

        if (!empty($playerPromoter->path)) {
            PlayerPromoter::query()->where('player_id', '!=', $playerPromoter->player_id)
                ->whereIn('player_id', explode(',', $playerPromoter->path))
                ->update([
                    'team_profit_amount' => DB::raw("team_profit_amount - {$promoterProfitSettlementRecord->total_profit_amount}"),
                    'team_settlement_amount' => DB::raw("team_settlement_amount + $promoterProfitSettlementRecord->total_profit_amount"),
                ]);
        }
        if ($settlement > 0) {
            // ✅ 使用 WalletService 原子操作增加余额（Redis 作为唯一实时标准）
            $addResult = \app\service\WalletService::add(
                $playerPromoter->player_id,
                $settlement
            );

            $amountBefore = $addResult['old_balance'];
            $amountAfter = $addResult['balance'];

            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $playerPromoter->player_id;
            $playerDeliveryRecord->department_id = $playerPromoter->department_id;
            $playerDeliveryRecord->target = $promoterProfitSettlementRecord->getTable();
            $playerDeliveryRecord->target_id = $promoterProfitSettlementRecord->id;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_PROFIT;
            $playerDeliveryRecord->source = 'profit';
            $playerDeliveryRecord->amount = $settlement;
            $playerDeliveryRecord->amount_before = $amountBefore;
            $playerDeliveryRecord->amount_after = $amountAfter;
            $playerDeliveryRecord->tradeno = $promoterProfitSettlementRecord->tradeno ?? '';
            $playerDeliveryRecord->remark = '';
            $playerDeliveryRecord->save();

            // ✅ WalletService 已自动同步数据库，无需手动保存
        }
        $playerPromoter->push();
        DB::commit();
    } catch (Exception $e) {
        DB::rollback();
        throw new Exception($e->getMessage());
    }
}

/**
 * 机台洗分
 * @param Player $player
 * @param Machine $machine
 * @param string $path
 * @param int $is_system
 * @param bool $hasLottery
 * @return PlayerLotteryRecord|array|bool
 * @throws Exception
 * @throws PushException
 */
function machineWash(
    Player  $player,
    Machine $machine,
    string  $path = 'leave',
    int     $is_system = 0,
    bool    $hasLottery = false,
    int     $adminId = 0,
    string  $adminUsername = ''
): PlayerLotteryRecord|bool|array
{
    // ⚠️ C376 洗分日志（开始）
    if ($machine->code === 'C376') {
        Log::channel('machine_operations')->info('[C376-MachineWash] 开始洗分流程', [
            'machine_id' => $machine->id,
            'machine_code' => $machine->code,
            'player_id' => $player->id,
            'player_uuid' => $player->uuid,
            'path' => $path,
            'is_system' => $is_system,
            'has_lottery' => $hasLottery,
            'admin_id' => $adminId,
            'admin_username' => $adminUsername,
        ]);
    }

    // 分布式锁：防止上下分并发
    $actionLockerKey = 'machine_operation_lock_' . $machine->id;
    $lock = Locker::lock($actionLockerKey, 30, true);

    try {
        if (!$lock->acquire()) {
            throw new Exception(trans('machine_is_using_msg1', [], 'message'));
        }

        $lang = locale() ?? 'zh_CN';
        $services = MachineServices::createServices($machine, $lang);

        // ========== 业务层检查：机台是否已锁定（检查 Redis）==========
        // 硬件层锁定修改的是 Redis，所以必须检查 Redis 而不是 DB
        if ($services->has_lock == 1) {
            throw new Exception(trans('machine_has_lock', [], 'message'));
        }

        if ($services->last_point_at + 5 >= time()) {
            throw new Exception(trans('exception_msg.point_must_5seconds', [], 'message', $lang));
        }
        // 洗分限制（强制退出洗分）
        $giftPoint = getGivePoints($player->id, $machine->id);
        $gamingTurnPoint = 0; // 转数
        $gamingPressure = 0; // 压分
        $gamingScore = 0; // 得分
        $money = 0; // 机台下分
        //斯洛 需要判斷下分限制
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                // 弃台需要下转,下珠
                if ($path == 'leave') {
                    if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                        $services->sendCmd($services::PUSH . $services::PUSH_STOP, 0, 'player', $player->id,
                            $is_system);
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
                        }
                    }
                    if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id, $is_system);
                        }
                        $services->sendCmd($services::MACHINE_TURN, 0, 'player', $player->id, $is_system);
                        $services->sendCmd($services::MACHINE_SCORE, 0, 'player', $player->id, $is_system);
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id, $is_system);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id, $is_system);
                        }
                    }
                }
                $services->sendCmd($services::MACHINE_POINT, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::WIN_NUMBER, 0, 'player', $player->id, $is_system);
                $gamingTurnPoint = $services->player_win_number;
                $money = $services->point;
                if (!empty($giftPoint) && $path == 'leave') {
                    $money = max($money - $giftPoint['gift_point'], 0);
                }
                break;
            case GameType::TYPE_SLOT:
                if ($services->move_point == 1 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::MOVE_POINT_OFF, 0, 'player', $player->id, $is_system);
                }
                if ($services->auto == 1) {
                    $services->sendCmd($services::OUT_OFF, 0, 'player', $player->id, $is_system);
                }
                $services->sendCmd($services::STOP_ONE, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::STOP_TWO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::STOP_THREE, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::READ_SCORE, 0, 'player', $player->id, $is_system);
                Log::channel('song_slot_machine')->info('slot -> wash', [
                    'point' => $money,
                    'code' => $machine->code,
                    'bet' => $services->bet,
                    'player_pressure' => $services->player_pressure,
                ]);
                $services->sendCmd($services::READ_BET, 0, 'player', $player->id, $is_system);
                $gamingPressure = bcsub($services->bet, $services->player_pressure);
                $gamingScore = bcsub($services->win, $services->player_score);
                $money = $services->point;
                Log::channel('slot_machine')->info('slot -> wash', [
                    'point' => $money,
                    'code' => $machine->code,
                ]);
                if (!empty($giftPoint)) {
                    if ($money < $giftPoint['open_point'] * $giftPoint['condition']) {
                        $money = max($money - $giftPoint['gift_point'], 0);
                    }
                }
                break;
        }

        /** 彩金预留检查 */
        if ($hasLottery && $machine->type == GameType::TYPE_SLOT && $path == 'down' && $money > 0) {
            try {
                $playerLotteryRecord = (new LotteryServices())->setMachine($machine)->setPlayer($player)->fixedPotCheckLottery($money,
                    true);
                if ($playerLotteryRecord) {
                    return $playerLotteryRecord;
                }
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        }
        DB::beginTransaction();
        try {
            $washResult = null;
            if ($money >= 0) {
                $washResult = machineWashZero($player, $machine, $money, $is_system, max($gamingPressure, 0),
                    max($gamingScore, 0), max($gamingTurnPoint, 0), $path, $adminId, $adminUsername);
                $machine = $washResult['machine'];
            }
        if ($path == 'leave') {
            if ($services->keeping == 1) {
                // 更新保留日志
                updateKeepingLog($machine->id, $player->id);
            }
            $machine->gaming = 0;
            $machine->gaming_user_id = 0;
            $machine->save();

            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                $activityServices = new ActivityServices($machine, $player);
                $activityServices->playerFinishActivity(true);
            }
            /** TODO 计算打码量 */
        }
        // 斯洛离开机台或弃台下分重置活动 检查彩金中奖情况
        if ($machine->type == GameType::TYPE_SLOT) {
            // 离开机台参与活动结束
            $activityServices = new ActivityServices($machine, $player);
            $activityServices->playerFinishActivity(true);
            // 下分检查彩金获奖情况
            if ($money > 0) {
                $playerLotteryRecord = (new LotteryServices())->setMachine($machine)->setPlayer($player)->fixedPotCheckLottery($money,
                    false, $path == 'leave');
            }
        }

        // ✅ 硬件清零指令（在事务内执行，失败可回滚）
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::CLEAR_LOG, 0, 'player', $player->id, $is_system);
                break;
            case GameType::TYPE_SLOT:
                $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id, $is_system);
                $services->sendCmd($services::ALL_DOWN, 0, 'player', $player->id, $is_system);
                break;
        }

        // ✅ 硬件指令成功后才提交数据库
        DB::commit();

        // ✅ 立即更新 Redis 状态（保证 DB/Redis 一致性）
        // 优化：在钱包操作之前更新 Redis，缩小不一致时间窗口
        if ($path == 'leave') {
            $services->keeping_user_id = 0;
            $services->keeping = 0;
            $services->last_keep_at = 0;
            $services->keep_seconds = 0;
        }

        // ✅ Redis 清零操作（硬件已清零，同步 Redis）
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                $services->player_win_number = 0;
                break;
            case GameType::TYPE_SLOT:
                $services->player_pressure = 0;
                $services->player_score = 0;
                $services->bet = 0;
                break;
        }

        // ✅ 最后执行钱包加款（DB + Redis 已一致，钱包失败不影响状态一致性）
        if ($washResult && $washResult['game_amount'] > 0) {
            try {
                $addResult = \app\service\WalletService::add($player->id, $washResult['game_amount']);

                Log::info('[machineWash] 钱包加款成功', [
                    'player_id' => $player->id,
                    'amount' => $washResult['game_amount'],
                    'before' => $washResult['before_balance'],
                    'after' => $addResult['balance'],
                ]);

                // ✅ 推送余额变化到客户端
                \app\service\BalancePushService::pushBalanceChange(
                    $player->id,
                    $washResult['before_balance'],
                    $addResult['balance'],
                    'settle',  // 下分加款视为结算
                    [
                        'platform' => $machine->name ?? $machine->code,
                        'machine_id' => $machine->id,
                    ]
                );

            } catch (Exception $walletError) {
                // ❌ CRITICAL：钱包加款失败，但 DB + Redis 已一致
                Log::critical('[machineWash] 钱包加款失败，需要人工介入', [
                    'player_id' => $player->id,
                    'amount' => $washResult['game_amount'],
                    'before_balance' => $washResult['before_balance'],
                    'error' => $walletError->getMessage(),
                    'action' => '已洗分成功（DB+硬件+Redis），但钱包未加款，请立即手动给玩家加款',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);

                // 发送 Telegram CRITICAL 告警
                try {
                    $telegramConfig = config('telegram');
                    if ($telegramConfig && !empty($telegramConfig['bot_token']) && !empty($telegramConfig['chat_id'])) {
                        $telegram = new \app\service\TelegramService($telegramConfig['bot_token'], $telegramConfig['chat_id']);
                        $telegram->sendAlert([
                            'datetime' => new \DateTime(),
                            'level_name' => 'CRITICAL',
                            'message' => '洗分成功但钱包加款失败',
                            'context' => [
                                'player_id' => $player->id,
                                'machine_id' => $machine->id,
                                'amount' => $washResult['game_amount'],
                                'action' => '请立即手动给玩家加款',
                            ],
                        ]);
                    }
                } catch (Exception $telegramError) {
                    Log::error('[machineWash] Telegram 告警发送失败', [
                        'error' => $telegramError->getMessage(),
                    ]);
                }

                // ⚠️ 不抛出异常，返回成功（因为 DB + Redis 已一致，硬件已清零）
                // 玩家会收到成功提示，但钱包暂时未到账
                // 运维会收到 CRITICAL 告警，立即手动加款
            }
        }

        } catch (Exception $e) {
            DB::rollback();

            // ⚠️ C376 洗分日志（失败）
            if ($machine->code === 'C376') {
                Log::channel('machine_operations')->error('[C376-MachineWash] 洗分失败', [
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'player_id' => $player->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            throw new Exception($e->getMessage());
        }

        // ✅ CRITICAL：DB 事务提交后，清除 Machine 缓存
        // 必须在 commit 后才能清除，否则其他进程会读到未提交的数据并缓存
        if ($path == 'leave') {
            \app\service\machine\MachineOperationService::clearMachineCache($machine);
        }

        // 游戏结束同步Redis彩金到数据库（新版：独立彩池模式）
        // 强制同步所有彩金的Redis数据到数据库
        try {
            LotteryServices::forceSyncRedisToDatabase();
        } catch (Exception $e) {
            Log::error('游戏结束同步彩金失败: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
        queueClient::send('media-recording', [
            'machine_id' => $machine->id,
            'action' => 'stop',
        ], 10);
        //下分成功 下分&下轉限制歸零 開獎中結束 關閉 push auto
        $services->last_play_time = time();
        if ($path == 'leave') {
            $services->gaming_user_id = 0;
            $services->gaming = 0;
            // ✅ keeping、player_pressure、player_score、player_win_number 已在事务提交后立即更新，此处无需重复
            $services->player_open_point = 0;
            $services->player_wash_point = 0;
        }
        switch ($machine->type) {
            case GameType::TYPE_STEEL_BALL:
                if ($path == 'leave') {
                    $services->gift_bet = 0;
                    Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
                }
                break;
            case GameType::TYPE_SLOT:
                Cache::delete('gift_cache_' . $machine->id . '_' . $player->id);
                break;
        }

        // 清理消息缓存
        LotteryServices::clearNoticeCache($player->id, $machine->id);

        // ⚠️ C376 洗分日志（成功）
        if ($machine->code === 'C376') {
            Log::channel('machine_operations')->info('[C376-MachineWash] 洗分成功完成', [
                'machine_id' => $machine->id,
                'machine_code' => $machine->code,
                'player_id' => $player->id,
                'path' => $path,
                'wash_point' => $washResult['wash_point'] ?? 0,
                'gaming_turn_point' => $washResult['gaming_turn_point'] ?? 0,
                'gaming_pressure' => $washResult['gaming_pressure'] ?? 0,
                'gaming_score' => $washResult['gaming_score'] ?? 0,
                'has_lottery' => isset($playerLotteryRecord),
            ]);
        }

        return $playerLotteryRecord ?? true;

    } finally {
        // 释放锁
        try {
            if (isset($lock) && $lock->isAcquired()) {
                $lock->release();
            }
        } catch (\Exception $lockError) {
            \support\Log::critical('[machineWash] 锁释放失败', [
                'machine_id' => $machine->id,
                'lock_key' => $actionLockerKey ?? null,
                'error' => $lockError->getMessage(),
            ]);
        }
    }
}

/**
 * 洗分清零算法
 * @param Player $player
 * @param Machine $machine
 * @param $money
 * @param int $is_system
 * @param int $gamingPressure
 * @param int $gamingScore
 * @param int $gamingTurnPoint
 * @param string $action
 * @return Machine
 * @throws Exception
 */
function machineWashZero(
    Player  $player,
    Machine $machine,
            $money,
    int     $is_system = 0,
    int     $gamingPressure = 0,
    int     $gamingScore = 0,
    int     $gamingTurnPoint = 0,
    string  $action = 'leave',
    int     $adminId = 0,
    string  $adminUsername = ''
): array
{
    try {
        $services = MachineServices::createServices($machine);
        $control_open_point = !empty($machine->control_open_point) ? $machine->control_open_point : 100;
        //记录游戏局记录
        /** @var PlayerGameRecord $gameRecord */
        $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
            ->where('player_id', $player->id)
            ->where('status', PlayerGameRecord::STATUS_START)
            ->orderBy('created_at', 'desc')
            ->first();

        // ✅ 从 Redis 读取实时余额（Redis 作为唯一实时标准）
        $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

        if ($money > 0) {
            //api洗分
            $wash_point = $money;
            //依照比值轉成錢包幣值 無條件捨去
            $game_amount = floor($money * ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1));

            // ✅ CRITICAL 修复：不在这里加款，延后到 commit 后
            // 计算预期余额（用于记录）
            $afterGameAmount = $beforeGameAmount + $game_amount;
            if (!empty($gameRecord)) {
                $gameRecord->wash_point = bcadd($gameRecord->wash_point, $wash_point, 2);
                $gameRecord->wash_amount = bcadd($gameRecord->wash_amount, $game_amount, 2);
                $gameRecord->after_game_amount = $afterGameAmount;
                if ($action == 'leave') {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                    /** TODO 计算客损 */
                    $diff = bcsub($gameRecord->wash_amount, $gameRecord->open_amount, 2);
                    nationalPromoterSettlement([
                        ['player_id' => $player->id, 'bet' => 0, 'diff' => $diff]
                    ]);
                    if (!empty($player->recommend_id)) {
                        $recommendPromoter = Player::query()->find($player->recommend_id);
                        $gameRecord->national_damage_ratio = $recommendPromoter->national_promoter->level_list->damage_rebate_ratio ?? 0;
                    }
                }
                $gameRecord->save();
            }

            //添加机台点数转换记录
            $playerGameLog = addPlayerGameLog($player, $machine, $gameRecord, $control_open_point);
            $playerGameLog->wash_point = $wash_point;
            $playerGameLog->game_amount = $game_amount;
            $playerGameLog->before_game_amount = $beforeGameAmount;
            $playerGameLog->after_game_amount = $afterGameAmount;
            $playerGameLog->action = ($action == 'leave' ? PlayerGameLog::ACTION_LEAVE : PlayerGameLog::ACTION_DOWN);
            $playerGameLog->chip_amount = 0;
            if ($machine->type == GameType::TYPE_SLOT) {
                $ratio = ($machine->odds_x ?? 1) / ($machine->odds_y ?? 1);
                $playerGameLog->chip_amount = bcmul($gamingPressure, $ratio, 2);
            } elseif ($machine->type == GameType::TYPE_STEEL_BALL) {
                $playerGameLog->chip_amount = bcmul($machine->machineCategory?->turn_used_point ?? 0, $gamingTurnPoint);
            }
            extracted($is_system, $playerGameLog, $gamingPressure, $gamingScore, $gamingTurnPoint, $adminId, $adminUsername);

            //寫入金流明細
            $playerDeliveryRecord = new PlayerDeliveryRecord;
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerGameLog->getTable();
            $playerDeliveryRecord->target_id = $playerGameLog->id;
            $playerDeliveryRecord->machine_id = $machine->id;
            $playerDeliveryRecord->machine_name = $machine->name;
            $playerDeliveryRecord->machine_type = $machine->type;
            $playerDeliveryRecord->code = $machine->code;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_MACHINE_DOWN;
            $playerDeliveryRecord->source = 'game_machine';
            $playerDeliveryRecord->amount = $game_amount;
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = $playerGameLog->tradeno ?? '';
            $playerDeliveryRecord->remark = $playerGameLog->remark ?? '';
            $playerDeliveryRecord->user_id = $adminId;
            $playerDeliveryRecord->user_name = $adminUsername;
            $playerDeliveryRecord->save();

            //保存下分時間
            $services->last_point_at = time();
            //累計該玩家洗分
            $services->player_wash_point = bcadd($services->player_wash_point, $wash_point);

            // ✅ 余额变化后更新爆机状态
            \app\service\WalletService::checkMachineCrashAfterTransaction($player->id, $afterGameAmount, $beforeGameAmount);
        } else {
            //添加机台点数转换记录
            $playerGameLog = addPlayerGameLog($player, $machine, $gameRecord, $control_open_point);
            $playerGameLog->wash_point = 0;
            $playerGameLog->game_amount = 0;
            $playerGameLog->before_game_amount = $beforeGameAmount;
            $playerGameLog->after_game_amount = $beforeGameAmount;
            $playerGameLog->action = ($action == 'leave' ? PlayerGameLog::ACTION_LEAVE : PlayerGameLog::ACTION_DOWN);
            extracted($is_system, $playerGameLog, $gamingPressure, $gamingScore, $gamingTurnPoint, $adminId, $adminUsername);

            if (!empty($gameRecord)) {
                $gameRecord->after_game_amount = $beforeGameAmount;
                if ($action == 'leave') {
                    $gameRecord->status = PlayerGameRecord::STATUS_END;
                    /** TODO 计算客损 */
                    $diff = bcsub($gameRecord->wash_amount, $gameRecord->open_amount, 2);
                    nationalPromoterSettlement([
                        ['player_id' => $player->id, 'bet' => 0, 'diff' => $diff]
                    ]);
                    if (!empty($player->recommend_id)) {
                        $recommendPromoter = Player::query()->find($player->recommend_id);
                        $gameRecord->national_damage_ratio = $recommendPromoter->national_promoter->level_list->damage_rebate_ratio ?? 0;
                    }
                }
                $gameRecord->save();
            }
            //保存下分時間
            $services->last_point_at = time();
        }
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }

    // ✅ 返回机台和钱包信息（钱包操作延后到外层 commit 后）
    return [
        'machine' => $machine,
        'game_amount' => $money > 0 ? $game_amount : 0,
        'before_balance' => $beforeGameAmount,
        'after_balance' => $money > 0 ? $afterGameAmount : $beforeGameAmount,
    ];
}

/**
 * 添加游戏日志记录
 * @param Player $player
 * @param Machine $machine
 * @param PlayerGameRecord|null $gameRecord
 * @param int $control_open_point
 * @return PlayerGameLog
 */
function addPlayerGameLog(
    Player            $player,
    Machine           $machine,
    ?PlayerGameRecord $gameRecord,
    int               $control_open_point
): PlayerGameLog
{
    $odds = $machine->odds_x . ':' . $machine->odds_y;
    if ($machine->type == GameType::TYPE_STEEL_BALL) {
        $odds = $machine->machineCategory?->name ?? '未知机种';
    }
    $playerGameLog = new PlayerGameLog;
    $playerGameLog->player_id = $player->id;
    $playerGameLog->parent_player_id = $player->recommend_id ?? 0;
    $playerGameLog->agent_player_id = $player->recommend_promoter?->recommend_id ?? 0;
    $playerGameLog->department_id = $player->department_id;
    $playerGameLog->machine_id = $machine->id;
    $playerGameLog->game_record_id = isset($gameRecord) && !empty($gameRecord->id) ? $gameRecord->id : 0;
    $playerGameLog->game_id = $machine->machineCategory?->game_id ?? 0;
    $playerGameLog->type = $machine->type;
    $playerGameLog->odds = $odds;
    $playerGameLog->control_open_point = $control_open_point;
    $playerGameLog->open_point = 0;
    $playerGameLog->turn_used_point = $machine->machineCategory?->turn_used_point ?? 0;
    $playerGameLog->is_test = $player->is_test; //标记测试数据

    return $playerGameLog;
}

/**
 * 提取游戏日志数据
 * @param int $is_system
 * @param PlayerGameLog $playerGameLog
 * @param int $gamingPressure 押分
 * @param int $gamingScore 得分
 * @param int $gamingTurnPoint 转数
 * @return void
 */
function extracted(
    int           $is_system,
    PlayerGameLog $playerGameLog,
    int           $gamingPressure,
    int           $gamingScore,
    int           $gamingTurnPoint,
    int           $adminId = 0,
    string        $adminUsername = ''
): void
{
    $playerGameLog->is_system = $is_system;
    $playerGameLog->pressure = $gamingPressure;
    $playerGameLog->score = $gamingScore;
    $playerGameLog->turn_point = $gamingTurnPoint;
    $playerGameLog->user_id = $adminId;
    $playerGameLog->user_name = $adminUsername;
    $playerGameLog->save();
}

/**
 * 全民代理结算
 * @param $data
 * @return bool
 */
function nationalPromoterSettlement($data): bool
{
    foreach ($data as $item) {
        /** @var Player $player */
        $player = Player::query()->find($item['player_id']);
        //玩家上级详情
        $recommendPromoter = Player::query()->find($player->recommend_id);
        //计算所有玩家打码量
        if ($item['bet'] > 0) {
            //当前玩家打码量
            $player->national_promoter->chip_amount = bcadd($player->national_promoter->chip_amount, $item['bet'],
                2);
            //根据打码量查询玩家当前全民代理等级
            $levelId = LevelList::query()->where('department_id', $player->department_id)
                ->where('must_chip_amount', '<=',
                    $player->national_promoter->chip_amount)->orderBy('must_chip_amount', 'desc')->first();
            if (!empty($levelId) && isset($levelId->id)) {
                //根据打码量提升玩家全民代理等级
                $player->national_promoter->level = $levelId->id;
            }
            $player->push();
        }
        //当前玩家渠道未开通全民代理功能
        if ($player->channel->national_promoter_status == 0) {
            continue;
        }
        //上级是全民代理,并且当前玩家已充值激活全民代理身份
        if (!empty($recommendPromoter) && !empty($recommendPromoter->national_promoter) && $item['diff'] != 0 && !empty($player->national_promoter) && $player->national_promoter->status == 1 && $recommendPromoter->is_promoter < 1) {
            $damageRebateRatio = isset($recommendPromoter->national_promoter->level_list->damage_rebate_ratio) ? $recommendPromoter->national_promoter->level_list->damage_rebate_ratio : 0;
            $money = bcdiv(bcmul(-$item['diff'], $damageRebateRatio, 2), 100, 2);
            $recommendPromoter->national_promoter->pending_amount = bcadd($recommendPromoter->national_promoter->pending_amount,
                $money, 2);
            $recommendPromoter->push();
            /** @var NationalProfitRecord $nationalProfitRecord */
            $nationalProfitRecord = NationalProfitRecord::query()->where('uid', $player->id)
                ->where('type', 1)
                ->whereDate('created_at', date('Y-m-d'))
                ->lockForUpdate()
                ->first();
            if (!empty($nationalProfitRecord)) {
                $nationalProfitRecord->money = bcadd($nationalProfitRecord->money, $money, 2);
            } else {
                $nationalProfitRecord = new NationalProfitRecord();
                $nationalProfitRecord->uid = $player->id;
                $nationalProfitRecord->recommend_id = $player->recommend_id;
                $nationalProfitRecord->money = $money;
                $nationalProfitRecord->type = 1;
            }
            $nationalProfitRecord->save();
        }
    }
    return true;
}

/**
 * 发送提现待审核消息
 * @return void
 * @throws Exception
 */
function reviewedWithdrawMessage(): void
{
    $subQuery = PlayerWithdrawRecord::query()
        ->select(DB::raw('MAX(id) as id'))
        ->where('status', PlayerWithdrawRecord::STATUS_WAIT)
        ->groupBy('department_id');
    /** @var PlayerWithdrawRecord $playerWithdrawRecord */
    $playerWithdrawRecordList = PlayerWithdrawRecord::query()
        ->whereIn('id', $subQuery)
        ->get();
    if (!empty($playerWithdrawRecordList)) {
        /** @var PlayerWithdrawRecord $item */
        foreach ($playerWithdrawRecordList as $item) {
            sendSocketMessage('private-admin_group-channel-' . $item->department_id, [
                'msg_type' => 'player_create_withdraw_order',
                'id' => $item->id,
                'player_id' => $item->player_id,
                'player_name' => $item->player_name,
                'player_phone' => $item->player_phone,
                'money' => $item->money,
                'point' => $item->point,
                'status' => $item->status,
                'tradeno' => $item->tradeno,
            ]);
        }
    }
}

/**
 * 发送充值待审核消息
 * @return void
 * @throws Exception
 */
function reviewedRechargeMessage(): void
{
    $subQuery = PlayerRechargeRecord::query()
        ->select(DB::raw('MAX(id) as id'))
        ->where('status', PlayerRechargeRecord::STATUS_RECHARGING)
        ->whereIn('type', [PlayerRechargeRecord::TYPE_SELF, PlayerRechargeRecord::TYPE_BUSINESS])
        ->groupBy('department_id');
    /** @var PlayerRechargeRecord $playerRechargeRecord */
    $playerRechargeRecordList = PlayerRechargeRecord::query()
        ->whereIn('id', $subQuery)
        ->get();
    if (!empty($playerRechargeRecordList)) {
        /** @var PlayerRechargeRecord $item */
        foreach ($playerRechargeRecordList as $item) {
            sendSocketMessage('private-admin_group-channel-' . $item->department_id, [
                'msg_type' => 'player_examine_recharge_order',
                'id' => $item->id,
                'player_id' => $item->player_id,
                'player_name' => $item->player_name,
                'player_phone' => $item->player_phone,
                'money' => $item->money,
                'status' => $item->status,
                'tradeno' => $item->tradeno,
            ]);
        }
    }
}

/**
 * 全民代理分润结算
 * @return void
 */
function nationalPromoterRebate(): void
{
    $log = Log::channel('national_promoter');
    ini_set('memory_limit', '512M');
    $log->info('全民代理统计开始: NationalPromoterRebate' . date('Y-m-d H:i:s'));
    $time = date('Y-m-d H:i:s');
    $playGameRecord = PlayGameRecord::query()
        ->where('national_promoter_action', 0)
        ->where('created_at', '<=', $time)
        ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
        ->selectRaw("player_id, sum(bet) as all_bet, sum(diff) as all_diff")
        ->groupBy('player_id')
        ->get();
    if (empty($playGameRecord->toArray())) {
        $log->info('全民代理统计结束: NationalPromoterRebate' . date('Y-m-d H:i:s') . ' 未产生数据');
        return;
    }
    foreach ($playGameRecord as $item) {
        Db::beginTransaction();
        try {
            $log->info('全民代理统计: NationalPromoterRebate' . date('Y-m-d H:i:s'), $item->toArray());
            //计算所有玩家打码量
            if ($item->all_bet > 0 && !empty($item->player->national_promoter)) {
                //当前玩家打码量
                $item->player->national_promoter->chip_amount = bcadd($item->player->national_promoter->chip_amount,
                    $item->all_bet, 2);
                //根据打码量查询玩家当前全民代理等级
                /** @var LevelList $levelId */
                $levelId = LevelList::query()
                    ->where('department_id', $item->player->department_id)
                    ->where('must_chip_amount', '<=', $item->player->national_promoter->chip_amount)
                    ->orderBy('must_chip_amount', 'desc')
                    ->first();
                if (!empty($levelId) && isset($levelId->id)) {
                    //根据打码量提升玩家全民代理等级
                    $item->player->national_promoter->level = $levelId->id;
                }
                $item->player->push();
                if (!empty($item->player->recommend_id) && $item->all_diff != 0 && $item->player->national_promoter->status == 1 && !empty($levelId)) {
                    /** @var Player $recommendPromoter */
                    $recommendPromoter = Player::query()->with([
                        'national_promoter',
                        'national_promoter.level_list'
                    ])->find($item->player->recommend_id);
                    if (!empty($recommendPromoter->national_promoter) && $recommendPromoter->is_promoter < 1 && $recommendPromoter->status_national == 1) {
                        $damageRebateRatio = isset($recommendPromoter->national_promoter->level_list->damage_rebate_ratio) ? $recommendPromoter->national_promoter->level_list->damage_rebate_ratio : 0;
                        $money = bcdiv(bcmul(-$item->all_diff, $damageRebateRatio, 2), 100, 2);
                        $recommendPromoter->national_promoter->pending_amount = bcadd($recommendPromoter->national_promoter->pending_amount,
                            $money, 2);
                        $recommendPromoter->push();
                        /** @var NationalProfitRecord $nationalProfitRecord */
                        $nationalProfitRecord = NationalProfitRecord::query()->where('uid', $item->player->id)
                            ->where('type', 1)
                            ->whereDate('created_at', date('Y-m-d'))
                            ->first();
                        if (!empty($nationalProfitRecord)) {
                            $nationalProfitRecord->money = bcadd($nationalProfitRecord->money, $money, 2);
                        } else {
                            $nationalProfitRecord = new NationalProfitRecord();
                            $nationalProfitRecord->uid = $item->player->id;
                            $nationalProfitRecord->recommend_id = $item->player->recommend_id;
                            $nationalProfitRecord->money = $money;
                            $nationalProfitRecord->type = 1;
                        }
                        $nationalProfitRecord->save();
                    }
                }
            }
            PlayGameRecord::query()
                ->where('national_promoter_action', 0)
                ->where('settlement_status', PlayGameRecord::SETTLEMENT_STATUS_SETTLED)
                ->where('player_id', $item->player_id)
                ->where('created_at', '<=', $time)
                ->update([
                    'national_promoter_action' => 1,
                    'national_damage_ratio' => $damageRebateRatio ?? 0
                ]);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            $log->error('全民代理统计错误: NationalPromoterRebate' . date('Y-m-d H:i:s') . ' - ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    $log->info('全民代理统计结束: NationalPromoterRebate' . date('Y-m-d H:i:s'));
}

/**
 * 检查设备是否爆机
 *
 * 只检查钱包的 is_crashed 字段，不判断当前余额
 * 这样可以让最后一笔触发爆机的交易正常完成，从而更新爆机状态和发送通知
 *
 * @param Player $player 玩家对象
 * @return array 返回爆机状态信息 ['crashed' => bool, 'crash_amount' => float|null, 'current_amount' => float]
 */
function checkMachineCrash(Player $player): array
{
    // 🚀 优化 #1: 使用 Redis 缓存爆机状态
    $cacheKey = "machine_crash_status:{$player->id}";

    try {
        $cached = \support\Redis::get($cacheKey);

        if ($cached !== null && $cached !== false) {
            // 缓存命中，解析缓存数据
            return json_decode($cached, true);
        }
    } catch (\Exception $e) {
        // Redis 故障时降级到数据库查询
        Log::error('checkMachineCrash: Redis get failed', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }

    // 缓存未命中或 Redis 故障，从 Redis 读取实时余额 + 数据库读取爆机状态
    // ✅ Redis 作为余额的"唯一实时标准"
    $currentAmount = \app\service\WalletService::getBalance($player->id);

    // 仅从数据库读取爆机状态标记
    $wallet = PlayerPlatformCash::where('player_id', $player->id)
        ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF)
        ->first(['is_crashed']);

    $isCrashed = $wallet && $wallet->is_crashed == 1;

    // 获取爆机金额配置（用于返回信息）
    $crashAmount = null;
    $adminUserId = $player->store_admin_id ?? null;

    if ($adminUserId) {
        $crashSetting = StoreSetting::getSetting(
            'machine_crash_amount',
            $player->department_id,
            null,
            $adminUserId
        );
        $crashAmount = ($crashSetting && $crashSetting->status == 1) ? ($crashSetting->num ?? 0) : null;
    }

    $result = [
        'crashed' => $isCrashed,
        'crash_amount' => $crashAmount,
        'current_amount' => $currentAmount,
    ];

    // 🚀 优化 #2: 根据爆机状态设置不同的缓存过期时间
    try {
        $ttl = $isCrashed ? 3600 : 600;  // 爆机1小时，未爆机10分钟
        \support\Redis::setex($cacheKey, $ttl, json_encode($result));
    } catch (\Exception $e) {
        // 缓存写入失败不影响业务
        Log::error('checkMachineCrash: Redis setex failed', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }

    return $result;
}

/**
 * 通知设备爆机
 * @param Player $player 玩家对象
 * @param array $crashInfo 爆机信息
 * @return void
 */
function notifyMachineCrash(Player $player, array $crashInfo): void
{
    try {
        // 玩家端消息
        $playerMessage = [
            'msg_type' => 'machine_crash',
            'player_id' => $player->id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
            'message' => '⚠️ 您的設備餘額已達到爆機金額，請聯繫管理員處理！',
            'timestamp' => time(),
        ];

        // 后台消息（包含更多信息）
        $adminMessage = [
            'msg_type' => 'machine_crash',
            'event' => 'player_crashed',
            'player_id' => $player->id,
            'player_name' => $player->name ?? '',
            'player_uuid' => $player->uuid ?? '',
            'store_admin_id' => $player->store_admin_id ?? null,
            'department_id' => $player->department_id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
            'message' => "设备已爆机：{$player->name} (ID:{$player->id}) 余额达到 {$crashInfo['current_amount']}，超过爆机金额 {$crashInfo['crash_amount']}",
            'timestamp' => time(),
        ];

        // 1. 发送给玩家
        $playerChannel = 'player-' . $player->id;
        sendSocketMessage([$playerChannel], $playerMessage, 'system');

        // 2. 发送给渠道后台
        $channelAdminChannel = 'private-admin_group-channel-' . $player->department_id;
        sendSocketMessage($channelAdminChannel, $adminMessage, 'system');

        // 3. 创建通知记录（渠道后台）
        $channelNotice = new Notice();
        $channelNotice->department_id = $player->department_id;
        $channelNotice->player_id = $player->id;
        $channelNotice->source_id = $player->id;
        $channelNotice->type = Notice::TYPE_MACHINE_CRASH;
        $channelNotice->receiver = Notice::RECEIVER_DEPARTMENT;
        $channelNotice->is_private = 0;
        $channelNotice->title = '設備爆機通知';
        $channelNotice->content = "設備已爆機：玩家 {$player->name} (UID:{$player->uuid}) 餘額達到 " . number_format($crashInfo['current_amount'], 2) . "，超過爆機金額 " . number_format($crashInfo['crash_amount'], 2) . "，請聯繫管理員處理！";
        $channelNotice->save();

        Log::info('Machine crash notification sent', [
            'player_id' => $player->id,
            'player_name' => $player->name,
            'store_admin_id' => $player->store_admin_id,
            'department_id' => $player->department_id,
            'crash_amount' => $crashInfo['crash_amount'],
            'current_amount' => $crashInfo['current_amount'],
        ]);
    } catch (Exception $e) {
        Log::error('Failed to send machine crash notification', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * 计算爆机状态下允许的最大洗分金额
 * 用于渠道后台洗分：如果余额超过爆机金额，只能洗到爆机金额
 * @param Player $player 玩家对象
 * @param float $requestedAmount 请求洗分的金额
 * @return array 返回 ['allowed_amount' => float, 'is_limited' => bool, 'crash_info' => array]
 */
function calculateAllowedWithdrawAmount(Player $player, float $requestedAmount): array
{
    $crashCheck = checkMachineCrash($player);
    // ✅ 从 Redis 读取实时余额
    $currentAmount = \app\service\WalletService::getBalance($player->id);
    $allowedAmount = $requestedAmount;
    $isLimited = false;

    // 如果当前爆机，并且有爆机金额设置
    if ($crashCheck['crashed'] && $crashCheck['crash_amount'] > 0) {
        // 最多只能洗到刚好等于爆机金额
        // 即：当前余额 - 爆机金额 = 最大可洗金额
        $maxAllowedAmount = $currentAmount - $crashCheck['crash_amount'];

        if ($maxAllowedAmount < 0) {
            $maxAllowedAmount = 0;
        }

        if ($requestedAmount > $maxAllowedAmount) {
            $allowedAmount = $maxAllowedAmount;
            $isLimited = true;
        }
    }

    return [
        'allowed_amount' => $allowedAmount,
        'is_limited' => $isLimited,
        'crash_info' => $crashCheck,
        'original_amount' => $requestedAmount,
    ];
}

/**
 * 检查并通知爆机解锁
 * 用于洗分后检查是否已解锁爆机状态
 * @param Player $player 玩家对象
 * @param float $previousAmount 洗分前的余额
 * @return void
 */
function checkAndNotifyCrashUnlock(Player $player, float $previousAmount): void
{
    try {
        $crashCheckBefore = checkMachineCrash($player);

        // 如果当前没有爆机，检查之前是否爆机
        if (!$crashCheckBefore['crashed'] && $crashCheckBefore['crash_amount'] > 0) {
            // 检查之前的余额是否达到爆机金额
            $wasCrashed = $previousAmount >= $crashCheckBefore['crash_amount'];

            // 如果之前爆机，现在已解锁，发送通知
            if ($wasCrashed) {
                // 玩家端消息
                $playerMessage = [
                    'msg_type' => 'machine_crash_unlock',
                    'player_id' => $player->id,
                    'crash_amount' => $crashCheckBefore['crash_amount'],
                    'current_amount' => $crashCheckBefore['current_amount'],
                    'message' => '✓ 您的设备爆机状态已解除，可继续正常使用。',
                    'timestamp' => time(),
                ];

                // 1. 发送给玩家
                $playerChannel = 'player-' . $player->id;
                sendSocketMessage([$playerChannel], $playerMessage, 'system');

                Log::info('Machine crash unlock notification sent', [
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                    'store_admin_id' => $player->store_admin_id,
                    'department_id' => $player->department_id,
                    'previous_amount' => $previousAmount,
                    'current_amount' => $crashCheckBefore['current_amount'],
                    'crash_amount' => $crashCheckBefore['crash_amount'],
                ]);
            }
        }
    } catch (Exception $e) {
        Log::error('Failed to check and notify crash unlock', [
            'player_id' => $player->id,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * 记录 Lua 脚本调用审计日志
 *
 * 统一记录所有 Lua 脚本调用的关键参数，便于审计和问题排查
 *
 * @param string $operation 操作类型（bet/settle/cancel）
 * @param string $platform 平台代码
 * @param int $playerId 玩家ID
 * @param array $params Lua 脚本参数
 * @param string|null $channel 日志通道（默认为 platform_server）
 */
function logLuaScriptCall(string $operation, string $platform, int $playerId, array $params, ?string $channel = null): void
{
    // ATG/ATG2/ATG3 共享 atg_server 日志通道
    if ($channel === null) {
        if (in_array($platform, ['ATG', 'ATG2', 'ATG3'])) {
            $channel = 'atg_server';
        } else {
            $channel = strtolower($platform) . '_server';
        }
    }

    // 提取关键参数
    $logData = [
        'operation' => $operation,
        'platform' => $platform,
        'player_id' => $playerId,
        'order_no' => $params['order_no'] ?? 'N/A',
        'amount' => $params['amount'] ?? 'N/A',
        'refund_amount' => $params['refund_amount'] ?? 'N/A',
        'diff' => $params['diff'] ?? 'N/A',
        'platform_id' => $params['platform_id'] ?? 'N/A',
        'game_code' => $params['game_code'] ?? 'N/A',
        'transaction_type' => $params['transaction_type'] ?? 'N/A',
    ];

    // 移除 N/A 值，保持日志简洁
    $logData = array_filter($logData, function ($value) {
        return $value !== 'N/A';
    });

    \support\Log::channel($channel)->info(
        sprintf('[Lua审计] %s操作', ucfirst($operation)),
        $logData
    );
}

/**
 * 验证 Lua 脚本参数
 *
 * 用于在调用 RedisLuaScripts 的 atomicBet/atomicSettle/atomicCancel 前验证参数
 *
 * @param array $params 要验证的参数数组
 * @param array $rules 验证规则
 * @param string $operation 操作名称（用于错误消息）
 * @throws InvalidArgumentException 参数验证失败时抛出
 *
 * 规则格式：
 * [
 *     'field_name' => ['required', 'numeric', 'min:0'],
 *     'field_name2' => ['string'],
 * ]
 *
 * 支持的规则：
 * - required: 必需字段，不能为 null
 * - scalar: 必须是标量类型（string/int/float/bool）
 * - numeric: 必须是数字（int/float/numeric string）
 * - integer: 必须是整数
 * - string: 必须是字符串
 * - min:n: 最小值（仅用于数字）
 * - max:n: 最大值（仅用于数字）
 *
 * 示例：
 * validateLuaScriptParams($data, [
 *     'order_no' => ['required', 'string'],
 *     'amount' => ['required', 'numeric', 'min:0'],
 *     'platform_id' => ['required', 'integer'],
 *     'game_code' => ['scalar'],  // 可选字段，允许字符串或整数
 * ], 'atomicBet');
 */
function validateLuaScriptParams(array $params, array $rules, string $operation = 'Lua script'): void
{
    foreach ($rules as $field => $fieldRules) {
        $fieldRules = is_array($fieldRules) ? $fieldRules : [$fieldRules];
        $value = $params[$field] ?? null;

        // 检查 required
        if (in_array('required', $fieldRules)) {
            if ($value === null || $value === '') {
                throw new InvalidArgumentException(
                    sprintf('[%s] 参数验证失败: %s 是必需的', $operation, $field)
                );
            }
        }

        // 如果值为空且不是 required，跳过其他验证
        if ($value === null || $value === '') {
            continue;
        }

        // 检查 scalar (字符串、整数、浮点数、布尔值)
        if (in_array('scalar', $fieldRules) && !is_scalar($value)) {
            throw new InvalidArgumentException(
                sprintf('[%s] 参数验证失败: %s 必须是标量类型(string/int/float/bool)，实际类型: %s', $operation, $field, gettype($value))
            );
        }

        // 检查 string
        if (in_array('string', $fieldRules) && !is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('[%s] 参数验证失败: %s 必须是字符串，实际类型: %s', $operation, $field, gettype($value))
            );
        }

        // 检查 numeric
        if (in_array('numeric', $fieldRules) && !is_numeric($value)) {
            throw new InvalidArgumentException(
                sprintf('[%s] 参数验证失败: %s 必须是数字，实际值: %s', $operation, $field, var_export($value, true))
            );
        }

        // 检查 integer
        if (in_array('integer', $fieldRules) && !is_int($value) && !(is_numeric($value) && (int)$value == $value)) {
            throw new InvalidArgumentException(
                sprintf('[%s] 参数验证失败: %s 必须是整数，实际值: %s', $operation, $field, var_export($value, true))
            );
        }

        // 检查 min
        foreach ($fieldRules as $rule) {
            if (strpos($rule, 'min:') === 0) {
                $min = (float)substr($rule, 4);
                if (is_numeric($value) && (float)$value < $min) {
                    throw new InvalidArgumentException(
                        sprintf('[%s] 参数验证失败: %s 必须 >= %s，实际值: %s', $operation, $field, $min, $value)
                    );
                }
            }
        }

        // 检查 max
        foreach ($fieldRules as $rule) {
            if (strpos($rule, 'max:') === 0) {
                $max = (float)substr($rule, 4);
                if (is_numeric($value) && (float)$value > $max) {
                    throw new InvalidArgumentException(
                        sprintf('[%s] 参数验证失败: %s 必须 <= %s，实际值: %s', $operation, $field, $max, $value)
                    );
                }
            }
        }
    }
}

/**
 * 记录游戏平台交互日志
 *
 * @param string $platform 平台代码 (RSG, MT, BTG等)
 * @param string $action 操作类型 (bet, settle, cancel等)
 * @param array $request 请求数据
 * @param mixed $response 响应数据
 * @return void
 */
function logGameInteraction(string $platform, string $action, array $request, $response = null): void
{
    try {
        $logger = \support\Log::channel('game_interaction_log');

        $message = sprintf(
            '[%s-%s] Request: %s | Response: %s',
            strtoupper($platform),
            strtoupper($action),
            json_encode($request, JSON_UNESCAPED_UNICODE),
            json_encode($response, JSON_UNESCAPED_UNICODE)
        );

        $logger->info($message);
    } catch (\Throwable $e) {
        // 记录失败不影响主业务
    }
}

/**
 * 获取平台ID（带内存缓存）
 *
 * @param string $platform 平台代码
 * @return int|null 平台ID
 */
function getPlatformIdByCode(string $platform): ?int
{
    static $cache = [];

    if (!isset($cache[$platform])) {
        $cache[$platform] = \app\model\GamePlatform::query()
            ->where('code', $platform)
            ->value('id');
    }

    return $cache[$platform];
}

/**
 * 获取下注金额（带数据库降级）
 *
 * 优先从 Redis 获取下注记录，如果失败则从数据库查询
 * 用于解决 Redis 记录过期或不可用时的 diff 计算问题
 *
 * @param string $platform 平台代码 (KT, ATG, DG等)
 * @param string $orderNo 订单号
 * @param int|null $playerId 玩家ID（可选，用于数据库查询优化）
 * @param int|null $platformId 平台ID（可选，传入后避免子查询）
 * @return float 下注金额，如果未找到返回 0
 */
function getBetAmountWithFallback(string $platform, string $orderNo, ?int $playerId = null, ?int $platformId = null, ?\Monolog\Logger $logger = null): float
{
    $startTime = microtime(true);
    $betAmount = 0;
    $source = 'none';

    try {
        // 1️⃣ 优先从 Redis 获取
        $betRecordKey = "game:record:bet:{$platform}:{$orderNo}";
        $betRecord = \support\Redis::hGetAll($betRecordKey);

        if (!empty($betRecord) && isset($betRecord['amount'])) {
            // Redis 的 amount 是"分"，转换为"元"
            $amountInCents = $betRecord['amount'];
            $betAmount = round((float)$betRecord['amount'] / 100, 2);
            $source = 'redis';

            // 🔍 统一日志：记录转换
            if ($logger) {
                $logger->info('💰 [金额流转-查询] Redis → Controller', [
                    'order_no' => $orderNo,
                    'amount_cents' => $amountInCents,
                    'amount_yuan' => $betAmount,
                    'source' => 'redis',
                ]);
            }
        } else {
            // 2️⃣ Redis 未命中，从数据库降级查询
            $query = \app\model\PlayGameRecord::query()
                ->where('order_no', $orderNo);

            // ✅ 性能优化：优先使用传入的 platform_id，避免子查询
            if ($platformId !== null) {
                $query->where('platform_id', $platformId);
            } else {
                // 降级：使用缓存的 platform_id
                $cachedPlatformId = getPlatformIdByCode($platform);
                if ($cachedPlatformId) {
                    $query->where('platform_id', $cachedPlatformId);
                } else {
                    // 最后降级：子查询（性能最差）
                    $query->where('platform_id', function ($subQuery) use ($platform) {
                        $subQuery->select('id')
                            ->from('game_platform')
                            ->where('code', $platform)
                            ->limit(1);
                    });
                }
            }

            // 如果提供了玩家ID，添加条件优化查询
            if ($playerId !== null) {
                $query->where('player_id', $playerId);
            }

            $dbRecord = $query->first(['bet']);

            if ($dbRecord) {
                $betAmount = (float)$dbRecord->bet;
                $source = 'database';

                // 🚨 记录降级告警
                \support\Log::warning('[降级] Redis bet记录缺失，已从数据库查询', [
                    'platform' => $platform,
                    'order_no' => $orderNo,
                    'player_id' => $playerId,
                    'bet_amount' => $betAmount,
                    'query_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ]);
            } else {
                // 3️⃣ 数据库也没有，记录严重告警
                \support\Log::error('[严重] bet记录完全缺失（Redis + DB）', [
                    'platform' => $platform,
                    'order_no' => $orderNo,
                    'player_id' => $playerId,
                ]);
            }
        }

        // 📊 性能日志（仅在慢查询时记录）
        $duration = (microtime(true) - $startTime) * 1000;
        if ($duration > 50) {
            \support\Log::info('[性能] getBetAmountWithFallback 慢查询', [
                'platform' => $platform,
                'order_no' => $orderNo,
                'source' => $source,
                'bet_amount' => $betAmount,
                'duration_ms' => round($duration, 2),
            ]);
        }

        return $betAmount;

    } catch (\Throwable $e) {
        // 异常时返回 0 并记录错误
        \support\Log::error('[异常] getBetAmountWithFallback 查询失败', [
            'platform' => $platform,
            'order_no' => $orderNo,
            'player_id' => $playerId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return 0;
    }
}

/**
 * 验证退款金额是否合理（带数据库降级）
 *
 * 检查退款金额是否超过原下注金额，防止数据异常或恶意请求
 *
 * @param string $platform 平台代码
 * @param string $orderNo 订单号
 * @param float $refundAmount 退款金额
 * @param int|null $playerId 玩家ID（可选）
 * @param int|null $platformId 平台ID（可选，传入后避免子查询）
 * @return array ['valid' => bool, 'bet_amount' => float, 'message' => string]
 */
function validateRefundAmount(string $platform, string $orderNo, float $refundAmount, ?int $playerId = null, ?int $platformId = null): array
{
    try {
        // 获取原下注金额（带降级）
        $betAmount = getBetAmountWithFallback($platform, $orderNo, $playerId, $platformId);

        // 验证退款金额
        if ($refundAmount > $betAmount) {
            \support\Log::warning('[退款验证] 退款金额超过下注金额', [
                'platform' => $platform,
                'order_no' => $orderNo,
                'player_id' => $playerId,
                'refund_amount' => $refundAmount,
                'bet_amount' => $betAmount,
                'excess' => $refundAmount - $betAmount,
            ]);

            return [
                'valid' => false,
                'bet_amount' => $betAmount,
                'message' => sprintf(
                    '退款金额(%.2f)超过原下注金额(%.2f)',
                    $refundAmount,
                    $betAmount
                ),
            ];
        }

        // 验证通过
        return [
            'valid' => true,
            'bet_amount' => $betAmount,
            'message' => 'ok',
        ];

    } catch (\Throwable $e) {
        \support\Log::error('[退款验证] 验证失败', [
            'platform' => $platform,
            'order_no' => $orderNo,
            'refund_amount' => $refundAmount,
            'error' => $e->getMessage(),
        ]);

        // 异常时保守处理：允许退款（避免影响正常业务）
        return [
            'valid' => true,
            'bet_amount' => 0,
            'message' => 'validation_error',
        ];
    }
}

/**
 * 清除爆机状态缓存
 *
 * @param int $playerId 玩家ID
 * @return bool 是否成功
 */
function clearMachineCrashCache(int $playerId): bool
{
    try {
        $cacheKey = "machine_crash_status:{$playerId}";
        \support\Redis::del($cacheKey);

        \support\Log::info('clearMachineCrashCache: 缓存已清除', [
            'player_id' => $playerId,
        ]);

        return true;
    } catch (\Exception $e) {
        \support\Log::error('clearMachineCrashCache: 清除失败', [
            'player_id' => $playerId,
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}

if (!function_exists('checkMachineOpenAny')) {
    /**
     * 上任意分
     * @param Machine $machine
     * @param int $money
     * @param int $giftScore
     * @return float|int
     * @throws Exception
     */
    function checkMachineOpenAny(Machine $machine, int $money, int $giftScore): float|int
    {
        if (!is_numeric($money) || $money <= 0) {
            throw new InvalidArgumentException('Invalid money value');
        }
        if (!is_numeric($machine->odds_x) || $machine->odds_x <= 0) {
            throw new InvalidArgumentException('Invalid odds_x value');
        }
        if (!is_numeric($machine->odds_y) || $machine->odds_y <= 0) {
            throw new InvalidArgumentException('Invalid odds_y value');
        }
        if ($machine->odds_x == 0) {
            throw new Exception(trans('machine_odds_config_error', [], 'message'));
        }
        $yx = $machine->odds_y / $machine->odds_x;
        if ($machine->odds_y > $machine->odds_x && floor($yx) != $yx) {
            throw new Exception(trans('machine_odds_config_error', [], 'message'));
        }
        $open_score = $money * $machine->odds_y / $machine->odds_x;

        return floor($open_score) + $giftScore;
    }
}

if (!function_exists('machineOpenAnyFree')) {
    /**
     * 任意开分（扣款）
     *
     * ⚠️ 注意：虽然函数名叫 "Free"，但实际上会扣除玩家钱包余额
     *
     * 用途：
     * - 玩家正常上分（扣款）
     * - 管理员代操作上分（扣款）
     * - 管理员自定义开分（扣款）
     *
     * 如需真正的免费赠送（不扣款），请使用其他方法或新增专用函数
     *
     * @param Player $player 玩家对象
     * @param Machine $machine 机台对象
     * @param int $openScore 开分数值
     * @param int $adminId 管理员ID（0表示玩家操作）
     * @param string $adminUsername 管理员用户名
     * @param int|null $giftScore 赠送分数
     * @param int|null $giveRuleId 赠送规则ID
     * @return bool 成功返回 true
     * @throws Exception
     */
    function machineOpenAnyFree(Player $player, Machine $machine, int $openScore, int $adminId = 0, string $adminUsername = '', ?int $giftScore = 0, ?int $giveRuleId = null): bool
    {
        // ✅ 参数验证
        if ($openScore <= 0) {
            throw new \InvalidArgumentException('openScore must be greater than 0');
        }
        if ($giftScore < 0) {
            throw new \InvalidArgumentException('giftScore cannot be negative');
        }

        // 分布式锁：防止上下分并发
        $actionLockerKey = 'machine_operation_lock_' . $machine->id;
        $lock = Locker::lock($actionLockerKey, 30, true);

        try {
            if (!$lock->acquire()) {
                throw new Exception(trans('machine_is_using_msg1', [], 'message'));
            }

            $lang = locale() ?? 'zh_CN';
            $services = MachineServices::createServices($machine, $lang);

            // ========== 业务层检查：机台是否已锁定（检查 Redis）==========
            // 硬件层锁定修改的是 Redis，所以必须检查 Redis 而不是 DB
            if ($services->has_lock == 1) {
                throw new Exception(trans('machine_has_lock', [], 'message'));
            }

            if ($services->last_point_at + 5 >= time()) {
                throw new Exception(trans('exception_msg.point_must_5seconds', [], 'message', $lang));
            }

            DB::beginTransaction();
            $walletDeducted = false;  // 标记钱包是否已扣款
            $money = 0;  // ✅ 初始化，避免异常处理时 Undefined variable
            try {
            // ⚠️ checkMachineOpenAny 只验证 openScore（购买的分），不包含赠分
            // giftScore 会在硬件上分时额外加上
            $openScore = checkMachineOpenAny($machine, $openScore, 0);

            // ✅ 计算总上分（购买分 + 赠送分）
            $totalOpenScore = $openScore + $giftScore;

            //測試連線
            if ($machine->type == GameType::TYPE_STEEL_BALL) {
            } else {
                // ✅ 检查机台分数上限（包含赠分）
                if ($services->point + $totalOpenScore > 4000) {
                    throw new Exception(trans('machine_wash_point_limit_exceeded', [], 'message'));
                }
            }

            // ========== Phase 1: 计算扣款金额 ==========
            // ✅ 防御性检查：验证机台赔率配置
            if (!$machine->odds_y || $machine->odds_y <= 0) {
                throw new Exception(trans('machine_odds_config_error', [], 'message') . ': odds_y 无效');
            }
            if (!$machine->odds_x || $machine->odds_x <= 0) {
                throw new Exception(trans('machine_odds_config_error', [], 'message') . ': odds_x 无效');
            }

            // 计算实际扣款金额（游戏分 → 金额）
            $money = floor($openScore / $machine->odds_y * $machine->odds_x);

            // ✅ 验证计算结果的合理性
            if ($money <= 0) {
                throw new Exception(trans('amount_calculation_zero_or_negative', [], 'message'));
            }
            if ($money > 1000000) {  // 限制单次上分100万元
                Log::error('[machineOpenAnyFree] 计算金额超过限制', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'open_score' => $openScore,
                    'odds_x' => $machine->odds_x,
                    'odds_y' => $machine->odds_y,
                    'calculated_money' => $money,
                ]);
                throw new Exception(trans('amount_calculation_too_large', [], 'message'));
            }

            // ========== Phase 2: 扣除玩家钱包 ==========
            // ⚠️ 注意：这里会扣除玩家余额（虽然函数名叫 "Free"，但实际扣款）
            // 如需真正的免费赠送，请新增专用函数或添加 $free 参数
            $beforeGameAmount = \app\service\WalletService::getBalance($player->id);

            // ✅ CRITICAL FIX: 检查扣款结果（防止余额不足时继续上分）
            $deductResult = \app\service\WalletService::deduct($player->id, $money);

            if (!isset($deductResult['success']) || !$deductResult['success']) {
                $error = $deductResult['error'] ?? trans('insufficient_balance', [], 'message');
                Log::warning('[machineOpenAnyFree] 余额不足，拒绝上分', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'required_amount' => $money,
                    'current_balance' => $beforeGameAmount,
                    'error' => $error,
                ]);
                throw new Exception($error);
            }

            // ✅ 扣款成功，获取新余额
            $afterGameAmount = $deductResult['balance'];
            $walletDeducted = true;  // ← 只有成功才标记已扣款

            Log::info('[machineOpenAnyFree] 扣款成功', [
                'player_id' => $player->id,
                'machine_id' => $machine->id,
                'amount' => $money,
                'before_balance' => $beforeGameAmount,
                'after_balance' => $afterGameAmount,
            ]);

            // ✅ 推送余额变化到客户端
            \app\service\BalancePushService::pushBalanceChange(
                $player->id,
                $beforeGameAmount,
                $afterGameAmount,
                'bet',  // 上分扣款视为下注
                [
                    'platform' => $machine->name ?? $machine->code,
                    'machine_id' => $machine->id,
                ]
            );

            // ========== Phase 3: PlayerGameRecord 创建或更新 ==========
            /** @var PlayerGameRecord $gameRecord */
            $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
                ->where('player_id', $player->id)
                ->where('status', PlayerGameRecord::STATUS_START)
                ->orderBy('created_at', 'desc')
                ->first();

            if (empty($gameRecord)) {
                // 首次上分，创建新的游戏记录
                $gameRecord = new PlayerGameRecord();
                $gameRecord->game_id = $machine->machineCategory?->game_id ?? 0;
                $gameRecord->machine_id = $machine->id;
                $gameRecord->player_id = $player->id;
                $gameRecord->parent_player_id = $player->recommend_id ?? 0;
                $gameRecord->agent_player_id = $player->recommend_promoter?->recommend_id ?? 0;
                $gameRecord->type = $machine->type;
                $gameRecord->code = $machine->code;
                $gameRecord->odds = $machine->odds_x . ':' . $machine->odds_y;
                $gameRecord->status = PlayerGameRecord::STATUS_START;
                $gameRecord->open_point = $openScore;      // ✅ 修复：设置为实际上分金额
                $gameRecord->open_amount = $money;         // ✅ 修复：设置为实际扣款金额
                $gameRecord->give_amount = $giftScore;     // ✅ 修复：设置为实际赠送金额
                $gameRecord->wash_point = 0;
                $gameRecord->wash_amount = 0;
                $gameRecord->after_game_amount = $afterGameAmount;  // ✅ 修复：应该是扣款后余额
                $gameRecord->created_at = date('Y-m-d H:i:s');
                $gameRecord->updated_at = date('Y-m-d H:i:s');
                $gameRecord->save();

                Log::info('[machineOpenAnyFree] 创建新的游戏记录', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'game_record_id' => $gameRecord->id,
                    'open_point' => $openScore,
                    'open_amount' => $money,
                ]);
            }
            // ✅ 检查游戏记录是否过期（超过48小时且被其他玩家占用）
            else if (time() - strtotime($gameRecord->updated_at) > 24 * 60 * 60 * 2
                && $machine->gaming_user_id != $player->id) {
                // 旧记录设置为结束状态
                $gameRecord->status = PlayerGameRecord::STATUS_END;
                $gameRecord->save();

                Log::info('[machineOpenAnyFree] 游戏记录过期，创建新记录', [
                    'old_game_record_id' => $gameRecord->id,
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'expired_hours' => round((time() - strtotime($gameRecord->updated_at)) / 3600, 1),
                ]);

                // 创建新的游戏记录
                $gameRecord = new PlayerGameRecord();
                $gameRecord->game_id = $machine->machineCategory?->game_id ?? 0;
                $gameRecord->machine_id = $machine->id;
                $gameRecord->player_id = $player->id;
                $gameRecord->parent_player_id = $player->recommend_id ?? 0;
                $gameRecord->agent_player_id = $player->recommend_promoter?->recommend_id ?? 0;
                $gameRecord->type = $machine->type;
                $gameRecord->code = $machine->code;
                $gameRecord->odds = $machine->odds_x . ':' . $machine->odds_y;
                $gameRecord->status = PlayerGameRecord::STATUS_START;
                $gameRecord->open_point = $openScore;
                $gameRecord->open_amount = $money;
                $gameRecord->give_amount = $giftScore;
                $gameRecord->wash_point = 0;
                $gameRecord->wash_amount = 0;
                $gameRecord->after_game_amount = $afterGameAmount;
                $gameRecord->created_at = date('Y-m-d H:i:s');
                $gameRecord->updated_at = date('Y-m-d H:i:s');
                $gameRecord->save();

                Log::info('[machineOpenAnyFree] 游戏记录过期，已创建新记录', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'new_game_record_id' => $gameRecord->id,
                    'open_point' => $openScore,
                    'open_amount' => $money,
                ]);
            } else {
                // 更新现有游戏记录（第2次及后续上分）
                $gameRecord->open_point = bcadd($gameRecord->open_point, $openScore, 2);
                $gameRecord->open_amount = bcadd($gameRecord->open_amount, $money, 2);
                $gameRecord->give_amount = bcadd($gameRecord->give_amount, $giftScore, 2);
                $gameRecord->save();

                Log::info('[machineOpenAnyFree] 更新现有游戏记录', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'game_record_id' => $gameRecord->id,
                    'total_open_point' => $gameRecord->open_point,
                    'total_open_amount' => $gameRecord->open_amount,
                ]);
            }

            // ========== Phase 4: 创建游戏日志 ==========
            //上任意分
            $odds = $machine->odds_x . ':' . $machine->odds_y;
            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                $odds = $machine->machineCategory?->name ?? '未知机种';
            }
            $playerGameLog = new PlayerGameLog;
            $playerGameLog->player_id = $player->id;
            $playerGameLog->department_id = $player->department_id;
            $playerGameLog->parent_player_id = $player->recommend_id ?? 0;
            $playerGameLog->agent_player_id = $player->recommend_promoter?->recommend_id ?? 0;
            $playerGameLog->game_id = $machine->machineCategory?->game_id ?? 0;
            $playerGameLog->machine_id = $machine->id;
            $playerGameLog->type = $machine->type;
            $playerGameLog->odds = $odds;
            $playerGameLog->control_open_point = $machine->control_open_point;
            $playerGameLog->open_point = $openScore;
            $playerGameLog->wash_point = 0;
            $playerGameLog->gift_point = 0;
            $playerGameLog->game_amount = $money;  // ✅ 修复：实际扣款金额
            $playerGameLog->before_game_amount = $beforeGameAmount;
            $playerGameLog->after_game_amount = $afterGameAmount;  // ✅ 修复：扣款后余额
            $playerGameLog->game_record_id = $gameRecord->id;
            $playerGameLog->user_id = $adminId;
            $playerGameLog->action = PlayerGameLog::ACTION_OPEN;
            $playerGameLog->user_name = $adminUsername;
            $playerGameLog->is_test = $player->is_test; //标记测试数据
            $playerGameLog->save();

            // 记录赠点信息（如果有赠点规则）
            if ($giveRuleId && $giftScore > 0) {
                $machineCategoryGiveRule = MachineCategoryGiveRule::find($giveRuleId);
                if ($machineCategoryGiveRule) {
                    $playersGiftRecord = new PlayerGiftRecord();
                    $playersGiftRecord->player_game_log_id = $playerGameLog->id;
                    $playersGiftRecord->machine_category_give_rule_id = $machineCategoryGiveRule->id;
                    $playersGiftRecord->machine_id = $machine->id;
                    $playersGiftRecord->player_id = $player->id;
                    $playersGiftRecord->player_name = $player->name;
                    $playersGiftRecord->machine_name = $machine->name;
                    $playersGiftRecord->machine_type = $machine->type;
                    $playersGiftRecord->open_num = $machineCategoryGiveRule->open_num;
                    $playersGiftRecord->give_num = $machineCategoryGiveRule->give_num;
                    $playersGiftRecord->condition = $machineCategoryGiveRule->condition;
                    $playersGiftRecord->created_at = date('Y-m-d H:i:s');
                    $playersGiftRecord->updated_at = date('Y-m-d H:i:s');
                    $playersGiftRecord->save();
                }
            }

            // ========== Phase 5: 写入金流明细 ==========
            $playerDeliveryRecord = new PlayerDeliveryRecord();
            $playerDeliveryRecord->player_id = $player->id;
            $playerDeliveryRecord->department_id = $player->department_id;
            $playerDeliveryRecord->target = $playerGameLog->getTable();
            $playerDeliveryRecord->target_id = $playerGameLog->id;
            $playerDeliveryRecord->machine_id = $machine->id;
            $playerDeliveryRecord->machine_name = $machine->name;
            $playerDeliveryRecord->machine_type = $machine->type;
            $playerDeliveryRecord->code = $machine->code;
            $playerDeliveryRecord->type = PlayerDeliveryRecord::TYPE_MACHINE_UP;
            $playerDeliveryRecord->source = 'game_machine';
            $playerDeliveryRecord->amount = -$money;  // 负数表示扣款
            $playerDeliveryRecord->amount_before = $beforeGameAmount;
            $playerDeliveryRecord->amount_after = $afterGameAmount;
            $playerDeliveryRecord->tradeno = '';
            $playerDeliveryRecord->remark = '';
            $playerDeliveryRecord->user_id = $adminId;
            $playerDeliveryRecord->user_name = $adminUsername;
            $playerDeliveryRecord->save();

            // ========== Phase 6: 活动记录 ==========
            if ($player->channel && $player->channel->activity_status == 1) {
                $ActivityServices = new \app\service\ActivityServices($machine, $player);
                $ActivityServices->addPlayerActivityRecord();
            }

            // ========== Phase 7: 更新机台状态 ==========
            $isFirstOpen = ($machine->gaming_user_id == 0);
            if ($machine->gaming == 0) {
                $machine->last_game_at = date('Y-m-d H:i:s');
            }

            // ✅ 诊断日志：记录 Machine 更新前的状态
            $oldGamingUserId = $machine->gaming_user_id;
            $oldGaming = $machine->gaming;

            $machine->gaming = 1;
            $machine->gaming_user_id = $player->id;
            $machine->last_point_at = date('Y-m-d H:i:s');

            // ✅ 诊断日志：生成缓存 key（用于验证缓存是否会被清除）
            $cacheKey = sprintf('machine:domain:%s:port:%s:type:%s',
                $machine->domain, $machine->port, $machine->type
            );

            Log::info('[machineOpenAnyFree] 准备更新 Machine 模型', [
                'player_id' => $player->id,
                'machine_id' => $machine->id,
                'machine_code' => $machine->code,
                'old_gaming_user_id' => $oldGamingUserId,
                'new_gaming_user_id' => $player->id,
                'old_gaming' => $oldGaming,
                'new_gaming' => 1,
                'cache_key' => $cacheKey,
                'will_trigger_cache_delete' => ($oldGamingUserId != $player->id || $oldGaming != 1),
            ]);

            $machine->save();

            // ✅ 硬件开分指令（在事务内执行，失败可回滚）
            // 首次上分发送移分关闭指令
            if ($isFirstOpen) {
                //斯洛 移分off
                if ($machine->type == GameType::TYPE_SLOT && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                    $services->sendCmd($services::MOVE_POINT_OFF, 0, 'admin', $adminId);
                }
            }

            // 发送开分指令（包含赠送分）
            // ⚠️ 玩家扣款只扣 openScore 的金额，但硬件要给 totalOpenScore = openScore + giftScore
            $services->sendCmd($services::OPEN_ANY_POINT, $totalOpenScore, 'admin', $adminId);

            // ✅ 硬件指令成功后才提交数据库（DB 是唯一真实来源）
            DB::commit();

            // ✅ Redis 缓存更新（失败不影响业务，下次读取时从 DB 刷新）
            try {
                // ✅ 诊断日志：记录 Redis 更新前的值
                $oldRedisGamingUserId = $services->gaming_user_id ?? null;
                $oldRedisGaming = $services->gaming ?? null;

                Log::info('[machineOpenAnyFree] 准备更新 Redis', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'old_redis_gaming_user_id' => $oldRedisGamingUserId,
                    'new_redis_gaming_user_id' => $player->id,
                    'old_redis_gaming' => $oldRedisGaming,
                    'new_redis_gaming' => 1,
                ]);

                //累計該玩家開分（包含赠分）
                $services->gaming = 1;
                $services->gaming_user_id = $player->id;
                $services->player_open_point = bcadd($services->player_open_point, $totalOpenScore);
                $services->last_point_at = time();

                // ✅ 诊断日志：验证 Redis 更新成功
                $newRedisGamingUserId = $services->gaming_user_id ?? null;
                Log::info('[machineOpenAnyFree] Redis 更新成功', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'redis_gaming_user_id' => $newRedisGamingUserId,
                    'redis_gaming' => $services->gaming,
                    'update_success' => ($newRedisGamingUserId == $player->id),
                ]);

            } catch (\Exception $redisError) {
                Log::critical('[machineOpenAnyFree] Redis 缓存更新失败', [
                    'player_id' => $player->id,
                    'machine_id' => $machine->id,
                    'error' => $redisError->getMessage(),
                    'trace' => $redisError->getTraceAsString(),
                ]);
                // Redis 失败不影响业务，继续执行
            }

            // ✅ CRITICAL：DB 事务提交并且 Redis 更新后，才能清除 Machine 缓存
            // 原因：如果在 commit 前清除，其他进程读取时事务未提交，会把旧数据缓存起来
            $cacheResult = \app\service\machine\MachineOperationService::clearMachineCache($machine);

            Log::info('[machineOpenAnyFree] Machine 缓存已清除', [
                'player_id' => $player->id,
                'machine_id' => $machine->id,
                'cache_result' => $cacheResult,
            ]);

        } catch (\Exception $e) {
            DB::rollback();

            Log::error('[machineOpenAnyFree] 上分失败', [
                'player_id' => $player->id,
                'machine_id' => $machine->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'wallet_deducted' => $walletDeducted,
            ]);

            // ========== 上分失败处理：锁机台 + 人工补偿 ==========
            // 策略：不自动回滚钱包，而是锁定机台由客服人工补偿
            if ($walletDeducted) {
                try {
                    // ✅ 锁定机台（防止其他玩家使用）
                    // 🔴 重要：has_lock 只存在于 Redis，不在数据库表中
                    $services->has_lock = 1;  // 锁定 Redis（业务层检查这个）

                    Log::critical('[machineOpenAnyFree] 上分失败，机台已锁定（Redis），需人工补偿', [
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                        'machine_code' => $machine->code,
                        'deducted_amount' => $money,
                        'action' => '请客服人工补偿玩家并解锁机台',
                    ]);

                    // 发送 Telegram CRITICAL 告警
                    try {
                        $telegramConfig = config('telegram');
                        if ($telegramConfig && !empty($telegramConfig['bot_token']) && !empty($telegramConfig['chat_id'])) {
                            $telegram = new \app\service\TelegramService($telegramConfig['bot_token'], $telegramConfig['chat_id']);
                            $telegram->sendAlert([
                                'datetime' => new \DateTime(),
                                'level_name' => 'CRITICAL',
                                'message' => '上分失败，机台已锁定',
                                'context' => [
                                    'player_id' => $player->id,
                                    'machine_id' => $machine->id,
                                    'machine_code' => $machine->code,
                                    'deducted_amount' => $money,
                                    'action' => '请客服人工补偿玩家并解锁机台',
                                ],
                            ]);
                        }
                    } catch (\Exception $telegramError) {
                        Log::error('[machineOpenAnyFree] Telegram 告警发送失败', [
                            'error' => $telegramError->getMessage(),
                        ]);
                    }

                } catch (\Exception $lockError) {
                    Log::critical('[machineOpenAnyFree] 锁机台失败，双重故障', [
                        'player_id' => $player->id,
                        'machine_id' => $machine->id,
                        'deducted_amount' => $money,
                        'lock_error' => $lockError->getMessage(),
                        'action' => '紧急：手动锁机台并补偿玩家',
                    ]);
                }
            }

            throw new Exception($e->getMessage());
        }

        return true;

        } finally {
            // 释放锁
            try {
                if (isset($lock) && $lock->isAcquired()) {
                    $lock->release();
                }
            } catch (\Exception $lockError) {
                \support\Log::critical('[machineOpenAnyFree] 锁释放失败', [
                    'machine_id' => $machine->id,
                    'lock_key' => $actionLockerKey ?? null,
                    'error' => $lockError->getMessage(),
                ]);
            }
        }
    }
}

if (!function_exists('resetMachineTrans')) {
    /**
     * 重置机台(开启事务)
     * @param Machine $machine
     * @param Player $player
     * @param int $adminId
     * @param string $adminUsername
     * @return true
     * @throws Exception
     */
    function resetMachineTrans(Machine $machine, Player $player, int $adminId = 0, string $adminUsername = ''): bool
    {
        DB::beginTransaction();
        try {
            $lang = locale() ?? 'zh_CN';
            /** @var Jackpot|Slot $services */
            $services = MachineServices::createServices($machine, $lang);
            $gamingTurn = 0;
            $gamingScore = 0;
            $gamingPressure = 0;
            $isOnLine = true;
            $uid = $machine->domain . ':' . $machine->port;
            if (!Gateway::isUidOnline($uid)) {
                $isOnLine = false;
            }
            //取得玩家遊玩轉數/得分
            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                // 根据机器类型选择不同的计算方式
                if ($machine->control_type == Machine::CONTROL_TYPE_SONG) {
                    // 小淞机器：使用实时追踪的 player_win_number
                    $gamingTurn = $services->player_win_number;
                } else {
                    // 双美机器：使用原有逻辑（基于 win_number 和 player_turn_point）
                    $gamingTurn = bcsub($services->win_number, $machine->player_turn_point);
                }
            }
            if ($machine->type == GameType::TYPE_SLOT) {
                $autoUid = $machine->auto_card_domain . ':' . $machine->auto_card_port;
                $gamingScore = bcsub($services->win, $services->player_score);
                $gamingPressure = bcsub($services->bet, $services->player_pressure);
                if (!Gateway::isUidOnline($autoUid)) {
                    $isOnLine = false;
                }
            }
            // ✅ 记录清零前的机台分数（用于审计）
            $confiscatedPoint = $services->point;  // 即将被没收的分数
            $confiscatedAmount = 0;
            if ($confiscatedPoint > 0 && $machine->odds_y > 0 && $machine->odds_x > 0) {
                $confiscatedAmount = floor($confiscatedPoint / $machine->odds_y * $machine->odds_x);
            }

            // ✅ 记录详细的强制踢出日志（包含被没收的金额）
            Log::warning('[resetMachineTrans] 管理员强制踢出玩家，即将没收机台分数', [
                'operator_type' => 'admin',
                'admin_id' => $adminId,
                'admin_username' => $adminUsername,
                'player_id' => $player->id,
                'player_username' => $player->username,
                'machine_id' => $machine->id,
                'machine_code' => $machine->code,
                'machine_type' => $machine->type == GameType::TYPE_STEEL_BALL ? '钢珠机' : '斯洛机',
                'confiscated_point' => $confiscatedPoint,  // 被没收的分数
                'confiscated_amount' => $confiscatedAmount,  // 被没收的金额（人民币）
                'odds' => $machine->odds_x . ':' . $machine->odds_y,
                'is_online' => $isOnLine,
                'reason' => '强制踢出（不返还分数）',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

            if ($isOnLine) {
                switch ($machine->type) {
                    case GameType::TYPE_STEEL_BALL:
                        if ($machine->control_type == Machine::CONTROL_TYPE_MEI) {
                            $services->sendCmd($services::PUSH . $services::PUSH_STOP, 0, 'player', $player->id);
                        }
                        if ($services->auto == 1) {
                            $services->sendCmd($services::AUTO_UP_TURN, 0, 'player', $player->id);
                        }
                        if ($services->score > 0) {
                            $services->sendCmd($services::SCORE_TO_POINT, 0, 'player', $player->id);
                        }
                        if ($services->turn > 0) {
                            $services->sendCmd($services::TURN_DOWN_ALL, 0, 'player', $player->id);
                        }

                        // ✅ 强制踢出：清零机台珠数（没收分数）
                        if ($services->point > 0) {
                            $services->sendCmd($services::ALL_DOWN, 0, 'player', $player->id);
                        }
                        break;
                    case GameType::TYPE_SLOT:
                        if ($services->move_point == 1 && $machine->control_type == Machine::CONTROL_TYPE_MEI) {
                            $services->sendCmd($services::MOVE_POINT_OFF, 0, 'player', $player->id);
                        }
                        if ($services->auto == 1) {
                            $services->sendCmd($services::OUT_OFF, 0, 'player', $player->id);
                        } else {
                            $services->sendCmd($services::STOP_ONE, 0, 'player', $player->id);
                            $services->sendCmd($services::STOP_TWO, 0, 'player', $player->id);
                            $services->sendCmd($services::STOP_THREE, 0, 'player', $player->id);
                        }

                        // ✅ 强制踢出：清零机台分数（没收分数）
                        if ($services->point > 0) {
                            $services->sendCmd($services::WASH_ZERO, 0, 'player', $player->id);
                        }
                        break;
                }
            }

            $player_platform_wallet = PlayerPlatformCash::query()->where([
                'player_id' => $player->id,
                'platform_id' => PlayerPlatformCash::PLATFORM_SELF,
            ])->first();

            //记录游戏局记录
            /** @var PlayerGameRecord $gameRecord */
            $gameRecord = PlayerGameRecord::query()->where('machine_id', $machine->id)
                ->where('player_id', $player->id)
                ->where('status', PlayerGameRecord::STATUS_START)
                ->orderBy('created_at', 'desc')
                ->first();
            if (!empty($gameRecord)) {
                $gameRecord->status = PlayerGameRecord::STATUS_END;
                $gameRecord->save();
            }
            $odds = $machine->odds_x . ':' . $machine->odds_y;
            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                $odds = $machine->machineCategory?->name ?? '未知机种';
            }
            //添加机台点数转换记录
            $playerGameLog = new PlayerGameLog;
            $playerGameLog->player_id = $machine->gaming_user_id;
            $playerGameLog->parent_player_id = $player->recommend_id ?? 0;
            $playerGameLog->agent_player_id = $player->recommend_promoter?->recommend_id ?? 0;
            $playerGameLog->department_id = $player->department_id;
            $playerGameLog->machine_id = $machine->id;
            $playerGameLog->game_id = $machine->machineCategory?->game_id ?? 0;
            $playerGameLog->game_record_id = $gameRecord->id ?? 0;
            $playerGameLog->type = $machine->type;
            $playerGameLog->odds = $odds;
            $playerGameLog->control_open_point = $machine->control_open_point;
            $playerGameLog->open_point = 0;
            $playerGameLog->wash_point = $confiscatedPoint;  // ✅ 记录被没收的分数
            $playerGameLog->gift_point = 0;
            $playerGameLog->game_amount = -$confiscatedAmount;  // ✅ 记录被没收的金额（负数表示没收）
            $playerGameLog->pressure = max($gamingPressure, 0);
            $playerGameLog->score = max($gamingScore, 0);
            $playerGameLog->turn_point = max($gamingTurn, 0);
            $playerGameLog->before_game_amount = $player_platform_wallet->money ?? 0;
            $playerGameLog->after_game_amount = $player_platform_wallet->money ?? 0;  // 钱包余额不变
            $playerGameLog->is_system = 1;
            $playerGameLog->action = PlayerGameLog::ACTION_LEAVE;
            $playerGameLog->user_id = $adminId;
            $playerGameLog->user_name = $adminUsername;
            $playerGameLog->is_test = $player->is_test; //标记测试数据
            $playerGameLog->remark = "管理员{$adminUsername}强制踢出，没收{$confiscatedPoint}分（{$confiscatedAmount}元）";  // ✅ 添加备注
            $playerGameLog->save();

            $machine->gaming_user_id = 0;
            $machine->gaming = 0;
            $machine->player_turn_point = 0;
            $machine->player_seven_turn_point = 0;
            $machine->player_pressure = 0;
            $machine->player_score = 0;
            $machine->wash_limit = 0;
            $machine->open_point = 0;
            $machine->push_auto = 0;
            $machine->bonus_accumulate = 0;
            $machine->keep_seconds = 0;
            $machine->amount = 0;
            $machine->is_open = 0;
            $machine->save();

            $services->gaming_user_id = 0;
            $services->gaming = 0;
            $services->keeping_user_id = 0;
            $services->keeping = 0;
            $services->last_keep_at = 0;
            $services->keep_seconds = 0;
            if ($machine->type == GameType::TYPE_SLOT) {
                $services->player_pressure = 0;
                $services->player_score = 0;
            }
            if ($machine->type == GameType::TYPE_STEEL_BALL) {
                $services->player_win_number = 0;
            }
            $services->player_open_point = 0;
            $services->player_wash_point = 0;
            // 下分参与活动结束
            $activityServices = new ActivityServices($machine, $player);
            $activityServices->playerFinishActivity(true);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw new Exception($e->getMessage());
        }

        return true;
    }
}
