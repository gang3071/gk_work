<?php

namespace app\service\machine;

use app\model\AdminUser;
use app\model\GameType;
use app\model\Machine;
use app\model\MachineLotteryRecord;
use app\model\Notice;
use app\model\Player;
use app\service\LotteryServices;
use Exception;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Str;
use support\Cache;
use support\Log;
use support\Redis;
use Webman\Push\PushException;
use Webman\RedisQueue\Client;
use yzh52521\WebmanLock\Locker;

/**
 * 线下版钢珠机（小淞工控）
 *
 * 基于 2024线上85x 协议（小淞线下版钢珠工控协议）
 *
 * 协议要点：
 * - 波特率: 9600，停止位: 1
 * - 默认分机号: 46H（70号）
 * - 分数上限: 建议 5万
 * - 转数上限: 980转（硬限制）
 * - 校验算法: S1=XOR, S2=ADD取后2位
 *
 * 主要指令：
 * - 心跳: 46C0/46C6 (36字节)
 * - 上分: 46CA + 上分码 + 金额
 * - 下分: 46CC + 下分码 (三次握手)
 * - 故障排除: 46CCB4（会清除外部按钮计数器）
 * - 查询: 46CEA2(分数) / 46CEA5(得分) / 46CEA6(转数) / 46CEA9(累积转数)
 * - 操作: 46CEC1(上转) / 46CEC9(下转) / 46CECD(启动) / 46CECE(停止)
 *
 * 线下版特有功能：
 * - B5指令: 外部按钮开分次数（⚠️ 次数，不是金额）
 * - B7指令: 外部按钮洗分次数（⚠️ 次数，不是金额）
 * - 可配置分机号（默认46H）
 *
 * @property int $auto 自动状态（0=停止 1=启动）
 * @property int $reward_status 开奖状态（0=未开奖 1=开奖中）
 * @property int $play_start_time 开始游戏时间
 * @property int $gaming_user_id 游戏中玩家ID
 * @property int $gaming 是否游戏中
 * @property int $turn 剩余转数
 * @property int $point 当前分数（金额）
 * @property int $score 当前得分（WIN）
 * @property int $last_play_time 最后游戏时间
 * @property int $action_time 操作时间
 * @property int $win_number 累积转数（中洞对奖次数）
 * @property int $push_auto push auto状态
 * @property int $now_turn 当前累积转数
 * @property int $has_lock 机台锁定状态
 * @property int $ratio 扣趴比例（10-15%）
 * @property int $external_open_count 外部按钮开分次数（B5协议，次数非金额）
 * @property int $external_wash_count 外部按钮洗分次数（B7协议，次数非金额）
 *
 * @package app\service\machine
 * @author Claude Code
 * @date 2026-08-31
 */
class SongOfflineJackpot extends MachineServices implements BaseMachine
{
    // ==================== 查询指令（主动获取数据）====================
    // ✅ 新文档2024线上85x：全部使用46前缀
    const MACHINE_POINT = '46cea2';    // 查询机台目前分数（46 CE A2）→ 回复 46 C0 xx xx xx
    const MACHINE_SCORE = '46cea5';    // 查询机台目前得分WIN（46 CE A5）→ 回复 46 DA xx xx xx
    const MACHINE_TURN = '46cea6';     // 查询机台目前剩余转数（46 CE A6）→ 回复 46 DE xx xx
    const WIN_NUMBER = '46cea9';       // 查询机台累积转数（46 CE A9）→ 回复 46 D0 xx xx xx

    // ==================== 心跳状态码（被动接收）====================
    const GET_MACHINE_POINT = '46c0';  // 心跳-停止状态下的分数
    const AUTO_MACHINE_POINT = '46c6'; // 心跳-自动状态下的分数
    const GET_MACHINE_SCORE = '46da';  // 心跳-正常状态下的得分
    const FAULT1_MACHINE_SCORE = '46db'; // 心跳-故障1状态
    const FAULT_MACHINE_SCORE = '46dc';  // 心跳-故障2状态
    const GET_MACHINE_TURN = '46de';   // 心跳-剩余转数
    const GET_WIN_NUMBER = '46d0';     // 心跳-累积转数（未开奖）
    const REWARD_WIN_NUMBER = '46d5';  // 心跳-累积转数（开奖中）

    // ==================== 管理指令 ====================
    // ✅ 新文档2024线上85x：全部使用46前缀
    const CHECK = '46ccb4';            // 故障排除（46 CC B4）
    const CLEAR_LOG = '46ccba';        // 清除押得数值（46 CC BA）
    const MACHINE_OPEN = '46cebe';     // 开机（46 CE BE）
    const MACHINE_CLOSE = '46cebc';    // 关机（46 CE BC）
    const REWARD_SWITCH = '46ceb8';    // 查询大赏灯（46 CE B8）

    // ==================== 游戏控制指令 ====================
    // ✅ 新文档2024线上85x：全部使用46前缀
    const AUTO_UP_TURN = '46cecd';     // 启动机台（46 CE CD）
    const AUTO_STOP = '46cece';        // 停止机台（46 CE CE）
    const PUSH_THREE = '46ceb6';       // 连发PUSH（46 CE B6）
    const PUSH_ONE = '46ceb2';         // 单发PUSH（46 CE B2）

    // ==================== 转数/分数转换指令 ====================
    // ✅ 新文档2024线上85x：全部使用46前缀
    const POINT_TO_TURN = '46cec1';    // 分数变转数1次（46 CE C1）
    const TURN_UP_ALL = '46cecb';      // 分数全变转数（46 CE CB）
    const TURN_TO_POINT = '46ceca';    // 转数→分数-下转一次（推测46 CE CA）
    const TURN_DOWN_ALL = '46cec9';    // 转数换回分数（46 CE C9）
    const SCORE_TO_POINT = '46cec8';   // win换回分数（46 CE C8）

    // ==================== 资金操作指令 ====================
    // ✅ 上分和下分使用 46 前缀是正确的（文档：46 CA / 46 CC）
    const OPEN_ANY_POINT = '46ca';     // 开任意分数-上分（文档：46 CA）
    const WASH_ZERO = '46cc';          // 洗分清零-下分（文档：46 CC）

    // ==================== 心跳指令 ====================
    // ✅ 心跳使用 46 前缀是正确的
    const TESTING = '46c0';            // 心跳（停止状态）
    const TESTING2 = '46c6';           // 心跳（自动状态）

    // ==================== 业务限制 ====================
    const MAX_TURN = 980;              // 转数上限（协议限制）

    public $cacheData = [];
    public $expirationTime = 5000000;  // 5秒超时
    public $log = null;
    private string $extensionNumber = '46'; // 分机号（默认46H=70号）
    // ⚠️ P1-4修复：去重时间改用 Cache 存储，支持跨请求去重
    // 已移除 private array $lastCounterUpdateTime（实例变量跨请求失效）

    public function __construct(Machine $machine, $lang = 'zh_CN')
    {
        $this->machine = $machine;

        // ✅ 支持动态分机号配置（如果数据库中有配置则使用，否则使用默认46H）
        if (!empty($machine->extension_number)) {
            $this->extensionNumber = strtolower($machine->extension_number);
        }
        $this->cacheKey = self::CACHE_PREFIX . $this->machine->id;
        $this->cacheDataKey = self::MACHINE_DATA_PREFIX . $this->machine->id;

        // Redis缓存字段列表
        $this->cacheDataKeyArr = [
            $this->cacheDataKey . '_auto',           // 自动状态（0=停止 1=启动）
            $this->cacheDataKey . '_reward_status',  // 开奖状态（0=未开奖 1=开奖中）
            $this->cacheDataKey . '_play_start_time',
            $this->cacheDataKey . '_gaming_user_id', // 游戏中玩家ID
            $this->cacheDataKey . '_gaming',         // 是否游戏中
            $this->cacheDataKey . '_turn',           // 剩余转数
            $this->cacheDataKey . '_point',          // 当前分数（金额）
            $this->cacheDataKey . '_score',          // 当前得分（WIN）
            $this->cacheDataKey . '_last_play_time',
            $this->cacheDataKey . '_action_time',
            $this->cacheDataKey . '_win_number',     // 累积转数（中洞对奖次数）
            $this->cacheDataKey . '_push_auto',
            $this->cacheDataKey . '_now_turn',       // 当前累积转数
            $this->cacheDataKey . '_has_lock',       // 机台锁定状态
            $this->cacheDataKey . '_ratio',          // 扣趴比例（10-15%）
            // ✅ 外部按钮计数器（B5/B7协议，仅线下版）
            $this->cacheDataKey . '_external_open_count',  // 外部按钮开分次数（B5）
            $this->cacheDataKey . '_external_wash_count',  // 外部按钮洗分次数（B7）
        ];

        // 推送到前端的关键字段
        $this->machineInfo = [
            'auto',
            'reward_status',
            'turn',
            'point',
            'score',
            'win_number',
            'push_auto',
            'has_lock',
        ];

        $this->lang = $lang;
        $this->cacheData = $this->getMachineCache();
        $this->log = Log::channel('song_offline_jackpot_machine');
    }

    /**
     * 获取属性（从Redis读取）
     */
    public function __get($name)
    {
        $key = $this->cacheDataKey . '_' . $name;
        if (in_array($key, $this->cacheDataKeyArr)) {
            try {
                return Cache::get($key, 0);
            } catch (Exception $e) {
                try {
                    $value = Cache::get($key, 0);
                    \support\Log::warning('Redis缓存读取失败后重试成功', [
                        'machine_id' => $this->machine->id,
                        'field' => $name,
                        'error' => $e->getMessage()
                    ]);
                    return $value;
                } catch (Exception $e2) {
                    \support\Log::error('Redis缓存读取失败（重试1次后仍失败）', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'key' => $key,
                        'error' => $e2->getMessage()
                    ]);
                    return 0;
                }
            }
        }
        return null;
    }

    /**
     * 设置属性（写入Redis并推送WebSocket）
     */
    public function __set($name, $value)
    {
        $key = $this->cacheDataKey . '_' . $name;
        if (in_array($key, $this->cacheDataKeyArr)) {
            // ⚠️ CRITICAL：上分成功时（gaming_user_id 从0变为非0），立即更新 last_play_time
            if ($name === 'gaming_user_id' && !empty($value) && empty($this->gaming_user_id)) {
                Cache::set($this->cacheDataKey . '_last_play_time', time());
            }

            try {
                $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                if (!$saveResult) {
                    $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                }
            } catch (Exception $e) {
                try {
                    $saveResult = Cache::set($this->cacheDataKey . '_' . $name, $value);
                    \support\Log::warning('Redis缓存保存异常后重试成功', [
                        'machine_id' => $this->machine->id,
                        'field' => $name,
                        'error' => $e->getMessage()
                    ]);
                } catch (Exception $e2) {
                    $saveResult = false;
                    \support\Log::error('Redis缓存保存异常（重试1次后仍失败）', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value,
                        'error' => $e2->getMessage()
                    ]);
                }
            }

            // ✅ 关键字段保存失败时必须抛出异常
            if (!$saveResult) {
                $mustSuccessFields = ['has_lock', 'gaming', 'gaming_user_id'];
                if (in_array($name, $mustSuccessFields)) {
                    \support\Log::critical('关键字段Redis保存失败，抛出异常', [
                        'machine_id' => $this->machine->id,
                        'machine_code' => $this->machine->code,
                        'field' => $name,
                        'value' => $value
                    ]);

                    // ✅ 发送 Telegram CRITICAL 告警
                    try {
                        $token = env('TELEGRAM_BOT_TOKEN');
                        $chatId = env('TELEGRAM_CHAT_ID');

                        if (!empty($token) && !empty($chatId)) {
                            $telegram = new \app\service\TelegramService($token, $chatId, \Monolog\Logger::ERROR);
                            $telegram->sendAlert([
                                'datetime' => new \DateTime(),
                                'level_name' => 'CRITICAL',
                                'message' => '[MachineServices:SongOfflineJackpot] Redis 关键字段保存失败',
                                'context' => [
                                    'machine_id' => $this->machine->id ?? null,
                                    'machine_code' => $this->machine->code ?? '',
                                    'field' => $name,
                                    'value' => $value,
                                    'error' => 'Redis关键字段保存失败，可能导致DB/Redis数据不一致',
                                ],
                            ]);
                        }
                    } catch (\Throwable $telegramEx) {
                        \support\Log::warning('[MachineServices:SongOfflineJackpot] Telegram 告警发送失败', [
                            'error' => $telegramEx->getMessage(),
                        ]);
                    }

                    throw new Exception("Redis关键字段保存失败: {$name}，请立即检查Redis服务");
                }
            }

            // 推送WebSocket消息
            $machineCacheInfo = $this->getAllData() ?? [];
            if (!empty($machineCacheInfo)) {
                $info = [
                    'id' => $this->machine->id,
                    'last_game_at' => $this->machine->last_game_at,
                    'last_point_at' => 0, // ✅ 线下版不跟踪上下分时间
                    'odds_x' => $this->machine->odds_x,
                    'odds_y' => $this->machine->odds_y,
                    'type' => $this->machine->type,
                    'gaming_user_id' => $machineCacheInfo[$this->cacheDataKey . '_gaming_user_id'] ?? 0,
                    'gaming' => $machineCacheInfo[$this->cacheDataKey . '_gaming'] ?? 0,
                    'auto' => $machineCacheInfo[$this->cacheDataKey . '_auto'] ?? 0,
                    'reward_status' => $machineCacheInfo[$this->cacheDataKey . '_reward_status'] ?? 0,
                    'play_start_time' => $machineCacheInfo[$this->cacheDataKey . '_play_start_time'] ?? 0,
                    'turn' => $machineCacheInfo[$this->cacheDataKey . '_turn'] ?? 0,
                    'point' => $machineCacheInfo[$this->cacheDataKey . '_point'] ?? 0,
                    'score' => $machineCacheInfo[$this->cacheDataKey . '_score'] ?? 0,
                    'last_play_time' => $machineCacheInfo[$this->cacheDataKey . '_last_play_time'] ?? 0,
                    'action_time' => $machineCacheInfo[$this->cacheDataKey . '_action_time'] ?? 0,
                    'win_number' => $machineCacheInfo[$this->cacheDataKey . '_win_number'] ?? 0,
                    'push_auto' => $machineCacheInfo[$this->cacheDataKey . '_push_auto'] ?? 0,
                    'now_turn' => $machineCacheInfo[$this->cacheDataKey . '_now_turn'] ?? 0,
                    'has_lock' => $machineCacheInfo[$this->cacheDataKey . '_has_lock'] ?? 0,
                    'keep_seconds' => $this->machine->keep_seconds ?? 0, // ✅ 从数据库读取保留时间配置
                    // ✅ 玩家使用转数（线下版简化处理，设为win_number使计算结果为0）
                    // 父类会计算：win_number - player_win_number = 0（表示玩家未消耗转数）
                    'player_win_number' => $machineCacheInfo[$this->cacheDataKey . '_win_number'] ?? 0,
                    // ✅ 外部按钮计数器（仅线下版）
                    'external_open_count' => $machineCacheInfo[$this->cacheDataKey . '_external_open_count'] ?? 0,
                    'external_wash_count' => $machineCacheInfo[$this->cacheDataKey . '_external_wash_count'] ?? 0,
                ];

                switch ($name) {
                    case 'gaming_user_id':
                        if (!empty($this->machine->gamingPlayer)) {
                            $this->sendMachineRealTimeInformation($this->machine->gamingPlayer->department_id,
                                'game_start', $info);
                        }
                        break;
                    case 'auto':
                    case 'turn':
                    case 'win_number':
                    case 'push_auto':
                    case 'reward_status':
                    case 'score':
                    case 'external_open_count':  // ✅ 外部按钮次数变化
                    case 'external_wash_count':  // ✅ 外部按钮次数变化
                        if (!empty($this->machine->gamingPlayer)) {
                            $this->sendMachineRealTimeInformation($this->machine->gamingPlayer->department_id,
                                'game_info_change', $info);
                        }
                        break;
                }

                $currentGamingUserId = $machineCacheInfo[$this->cacheDataKey . '_gaming_user_id'] ?? 0;
                if (in_array($name, $this->machineInfo) && !empty($currentGamingUserId)) {
                    $this->sendMachineNowInfoMessage($currentGamingUserId, $this->machine->id, $name, $info);
                }
            }
        }
    }

    /**
     * 获取所有属性
     */
    public function getAllData(): iterable
    {
        return Cache::getMultiple($this->cacheDataKeyArr, 0);
    }

    /**
     * 线下钢珠消息处理（核心方法）
     *
     * 处理所有来自硬件的消息：
     * - 36字节心跳（46C0/46C6）
     * - 10/12/14/16字节指令回复
     *
     * @param string $msg 十六进制字符串（不含空格，如 "46C0051419D0030A0FDA000000DE0000AF12"）
     * @return bool
     */
    public function jackPotCmd(string $msg): bool
    {
        try {
            $len = mb_strlen($msg);

            // 校验消息长度
            // 10, 12, 14, 16 = 指令回复
            // 36 = 标准心跳
            // 46 = 心跳(36) + B5开分次数(10)
            // 50 = 心跳(36) + B7洗分次数(14)
            // 60 = 心跳(36) + B5(10) + B7(14)
            // ✅ P0-15修复：还可能是"指令回复+B5/B7"组合（如34=12+10+12）
            // 实际中可能有其他组合，采用模糊匹配
            $validLengths = [10, 12, 14, 16, 36, 46, 50, 58, 60];

            // 如果长度不在标准列表中，检查是否是合法的组合消息
            if (!in_array($len, $validLengths)) {
                // ✅ P0-15修复：只要 >= 10 就允许，可能是"回复+B5/B7"组合
                if ($len < 10) {
                    throw new Exception('指令长度错误: ' . $len);
                }

                // 检查是否包含B5/B7附加数据
                $hasB5B7 = (stripos($msg, 'b5') !== false || stripos($msg, 'b7') !== false);

                $this->log->info('收到非标准长度消息，尝试解析', [
                    'machine_code' => $this->machine->code,
                    'length' => $len,
                    'has_b5_b7' => $hasB5B7,
                    'msg_preview' => substr($msg, 0, min(60, $len)),
                ]);
            }

            $gamingUserId = $this->gaming_user_id;
            $orgRewardStatus = $this->reward_status;
            $orgTurn = $this->turn;
            $orgWinNumber = $this->win_number;

            // ==================== 处理心跳（可能包含附加数据）====================
            // ✅ P0-11修复：心跳+附加数据是组合消息，每部分有独立的S1/S2
            // ✅ P0-17修复：心跳可能不是36字符，需要检测并分离B5/B7
            // ⚠️ 区分查询响应和心跳：
            //    - 查询响应：46 C0 XX xx xx S1 S2（10字符）
            //    - 心跳：46 C0 ... （≥14字符，通常36字符）
            $prefix = substr($msg, 0, 2);
            $statusCode = substr($msg, 2, 2);
            $isHeartbeat = $prefix == '46' && ($statusCode == 'c0' || $statusCode == 'c6') && $len > 10;

            if ($isHeartbeat) {
                $heartbeat = '';
                $externalData = '';

                // ✅ 尝试分离心跳和B5/B7附加数据
                // 心跳可能的长度：36（标准）、14/12/10（短格式）
                $possibleHeartbeatLengths = [36, 14, 12, 10];

                foreach ($possibleHeartbeatLengths as $hbLen) {
                    if ($len >= $hbLen) {
                        $possibleHb = substr($msg, 0, $hbLen);

                        // 验证这段是否是有效的心跳（S1/S2校验）
                        $s1 = substr($possibleHb, -4, 2);
                        $s2 = substr($possibleHb, -2, 2);
                        $data = substr($possibleHb, 0, -4);

                        $calculatedS1 = self::calculateS1($data);
                        $calculatedS2 = self::calculateS2($data, $calculatedS1);

                        if ($s1 == $calculatedS1 && $s2 == $calculatedS2) {
                            // S1/S2校验通过，确认为有效心跳
                            $heartbeat = $possibleHb;

                            // 检查剩余部分
                            if ($len > $hbLen) {
                                $remaining = substr($msg, $hbLen);

                                // 剩余部分应该是空或以b5/b7开头
                                if (empty($remaining) || preg_match('/^b[57]/i', $remaining)) {
                                    $externalData = $remaining;
                                    break;
                                }
                            } else {
                                // 完整心跳，无附加数据
                                break;
                            }
                        }
                    }
                }

                // 验证是否成功分离
                if (empty($heartbeat)) {
                    throw new Exception('心跳S1/S2校验失败（尝试所有可能长度均失败）');
                }

                $result = $this->handleHeartbeat($heartbeat, $gamingUserId, $orgRewardStatus, $orgWinNumber, $orgTurn);

                // 处理B5/B7附加数据
                if (!empty($externalData)) {
                    $this->handleExternalButtonData($externalData);
                }

                return $result;
            }

            // ==================== 非心跳消息：分离主指令和B5/B7附加数据 ====================
            // ✅ P0-15修复：检查是否有B5/B7附加数据
            // ✅ P0-16修复：只在标准指令长度后查找，避免误判数据中的"b5"/"b7"字节
            $mainCmd = $msg;
            $externalData = '';

            // 标准指令长度（可能后面跟B5/B7）
            $stdLengths = [10, 12, 14, 16];

            foreach ($stdLengths as $cmdLen) {
                if ($len > $cmdLen) {
                    $possibleMain = substr($msg, 0, $cmdLen);
                    $possibleExt = substr($msg, $cmdLen);

                    // 检查剩余部分是否以b5或b7开头（区分大小写）
                    if (preg_match('/^b[57]/i', $possibleExt)) {
                        // 验证主指令的S1/S2校验和
                        $s1 = substr($possibleMain, -4, 2);
                        $s2 = substr($possibleMain, -2, 2);
                        $data = substr($possibleMain, 0, -4);

                        $calculatedS1 = self::calculateS1($data);
                        $calculatedS2 = self::calculateS2($data, $calculatedS1);

                        // 只有S1/S2都正确，才认为是组合消息
                        if ($s1 == $calculatedS1 && $s2 == $calculatedS2) {
                            $mainCmd = $possibleMain;
                            $externalData = $possibleExt;
                            break;
                        }
                    }
                }
            }

            // 校验主指令的 S1/S2
            $s1 = substr($mainCmd, -4, 2);
            $s2 = substr($mainCmd, -2, 2);
            $data = substr($mainCmd, 0, -4);

            $calculatedS1 = self::calculateS1($data);
            if ($s1 != $calculatedS1) {
                throw new Exception('指令S1校验失败');
            }

            $calculatedS2 = self::calculateS2($data, $calculatedS1);
            if ($s2 != $calculatedS2) {
                throw new Exception('指令S2校验失败');
            }

            $fun = substr($mainCmd, 0, 6);  // 前6位：功能码
            $fun1 = substr($mainCmd, 0, 4); // 前4位：分类码

            // ✅ 验证分机号匹配
            $receivedExtension = substr($mainCmd, 0, 2);
            if ($receivedExtension !== $this->extensionNumber) {
                $this->log->warning('收到不匹配的分机号消息', [
                    'machine_code' => $this->machine->code,
                    'expected' => $this->extensionNumber,
                    'received' => $receivedExtension,
                    'msg' => substr($mainCmd, 0, 20) . '...'
                ]);
                // 仍然处理（可能是配置错误），但记录日志
            }

            // ==================== 处理独立的外部按钮指令（如果有）====================
            // ✅ P1-3修复：独立B5/B7指令也需要记录到数据库，调用统一处理逻辑
            // ✅ P0-10修复：支持两种格式（防御性）：无前缀 "b5..." 或有前缀 "46b5..."

            // 检查独立B5指令
            if ($len >= 10) {
                // 格式1：无分机号前缀 "b5..."
                if (substr($msg, 0, 2) == 'b5') {
                    // 调用统一处理逻辑（会检测增量并记录到数据库）
                    $this->handleExternalButtonData(substr($msg, 0, 10));
                    return true;
                }

                // 格式2：有分机号前缀 "46b5..." 或 "{分机号}b5..."
                if ($len >= 12 && substr($msg, 2, 2) == 'b5') {
                    $this->handleExternalButtonData(substr($msg, 2, 10));
                    return true;
                }
            }

            // 检查独立B7指令
            if ($len >= 12) {
                // 格式1：无分机号前缀 "b7..."
                if (substr($msg, 0, 2) == 'b7') {
                    $availableLen = min(14, $len);
                    $this->handleExternalButtonData(substr($msg, 0, $availableLen));
                    return true;
                }

                // 格式2：有分机号前缀 "46b7..." 或 "{分机号}b7..."
                if ($len >= 14 && substr($msg, 2, 2) == 'b7') {
                    $availableLen = min(14, $len - 2);
                    $this->handleExternalButtonData(substr($msg, 2, $availableLen));
                    return true;
                }
            }

            // ==================== 处理指令回复 ====================
            $result = $this->handleCommandReply($mainCmd, $fun, $fun1, $gamingUserId);

            // ✅ P0-15修复：处理B5/B7附加数据
            if (!empty($externalData)) {
                $this->handleExternalButtonData($externalData);
            }

            return $result;

        } catch (Exception $e) {
            $this->log->error('消息处理错误', [
                'error' => $e->getMessage(),
                'msg' => $msg,
                'machine_code' => $this->machine->code,
            ]);
            return false;
        }
    }

    /**
     * 处理外部按钮开洗分数据（B5/B7指令）
     *
     * ⚠️ 注意：B5/B7 是操作次数，不是金额！
     *
     * @param string $data 附加数据（可能包含B5和/或B7指令）
     */
    private function handleExternalButtonData(string $data): void
    {
        // ✅ P0-2修复：使用分布式锁防止并发读取-计算-更新的竞态条件
        $lockKey = "external_button_parse_{$this->machine->id}";
        $lock =  Locker::lock($lockKey, 10);
        $lockAcquired = false;  // ✅ P2-9修复：标记锁是否成功获取

        try {
            if (!$lock->acquire()) {
                $this->log->warning('[外部按钮] 获取解析锁失败，跳过本次处理', [
                    'machine_code' => $this->machine->code,
                    'data_length' => strlen($data),
                ]);
                return;
            }
            $lockAcquired = true;  // ✅ 标记已成功获取锁

            $offset = 0;
            $len = strlen($data);
            $now = time();

            while ($offset < $len) {
                $cmd = substr($data, $offset, 2);

                if ($cmd == 'b5' && ($len - $offset) >= 10) {
                    // B5 xx xx S1 S2: 外部按钮开分次数（10字符）
                    // ⚠️ count 是次数，不是金额
                    $b5Data = substr($data, $offset, 10);

                    // ✅ P0-11修复：校验B5的S1/S2
                    $s1 = substr($b5Data, -4, 2);
                    $s2 = substr($b5Data, -2, 2);
                    $b5Content = substr($b5Data, 0, -4);

                    $calculatedS1 = self::calculateS1($b5Content);
                    if ($s1 != $calculatedS1) {
                        $this->log->error('[B5协议] S1校验失败', [
                            'machine_code' => $this->machine->code,
                            'data' => $b5Data,
                            'expected_s1' => $calculatedS1,
                            'actual_s1' => $s1,
                        ]);
                        $offset += 10;
                        continue;
                    }

                    $calculatedS2 = self::calculateS2($b5Content, $calculatedS1);
                    if ($s2 != $calculatedS2) {
                        $this->log->error('[B5协议] S2校验失败', [
                            'machine_code' => $this->machine->code,
                            'data' => $b5Data,
                            'expected_s2' => $calculatedS2,
                            'actual_s2' => $s2,
                        ]);
                        $offset += 10;
                        continue;
                    }

                    $newCount = hexdec(substr($b5Data, 2, 2)) * 100 + hexdec(substr($b5Data, 4, 2));

                    // ✅ P2-5修复：检测计数器溢出（B5最大9999）
                    if ($newCount > 9999) {
                        $this->log->error('[B5协议] 计数器溢出，数据异常', [
                            'machine_code' => $this->machine->code,
                            'new_count' => $newCount,
                            'data' => $b5Data,
                        ]);
                        $offset += 10;
                        continue;
                    }

                    $oldCount = $this->external_open_count ?? 0;

                    // ✅ 处理计数器变化
                    $result = $this->processCounterChange('open', $oldCount, $newCount, $now);

                    if ($result['should_update']) {
                        $this->external_open_count = $newCount;
                    }

                    $offset += 10;
                } elseif ($cmd == 'b7' && ($len - $offset) >= 12) {
                    // B7 xx xx xx S1 S2: 外部按钮洗分次数（实际可能是12或14字符）
                    // ⚠️ count 是次数，不是金额
                    $availableLen = min(14, $len - $offset);
                    $b7Data = substr($data, $offset, $availableLen);

                    if ($availableLen >= 12) {
                        // ✅ P0-11修复：校验B7的S1/S2
                        $s1 = substr($b7Data, -4, 2);
                        $s2 = substr($b7Data, -2, 2);
                        $b7Content = substr($b7Data, 0, -4);

                        $calculatedS1 = self::calculateS1($b7Content);
                        if ($s1 != $calculatedS1) {
                            $offset += $availableLen;
                            continue;
                        }

                        $calculatedS2 = self::calculateS2($b7Content, $calculatedS1);
                        if ($s2 != $calculatedS2) {
                            $offset += $availableLen;
                            continue;
                        }

                        $newCount = hexdec(substr($b7Data, 2, 2)) * 10000
                            + hexdec(substr($b7Data, 4, 2)) * 100
                            + hexdec(substr($b7Data, 6, 2));

                        // ✅ P2-5修复：检测计数器溢出（B7最大999999）
                        if ($newCount > 999999) {
                            $offset += $availableLen;
                            continue;
                        }

                        $oldCount = $this->external_wash_count ?? 0;

                        // ✅ 处理计数器变化
                        $result = $this->processCounterChange('wash', $oldCount, $newCount, $now);

                        if ($result['should_update']) {
                            $this->external_wash_count = $newCount;
                        }
                    }

                    $offset += $availableLen;
                } else {
                    // 未知指令，跳过2字符
                    $this->log->warning('未知的附加指令', [
                        'machine_code' => $this->machine->code,
                        'cmd' => $cmd,
                        'remaining_data' => substr($data, $offset)
                    ]);
                    break;
                }
            }
        } finally {
            // ✅ P2-9修复：只有成功获取锁才释放
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    /**
     * 处理计数器变化
     *
     * @param string $type 类型：open/wash
     * @param int $oldCount 旧计数
     * @param int $newCount 新计数
     * @param int $now 当前时间戳
     * @return array ['should_update' => bool, 'recorded' => bool, 'reason' => string]
     */
    private function processCounterChange(string $type, int $oldCount, int $newCount, int $now): array
    {
        // ========== 情况1：计数器减少 ==========
        if ($newCount < $oldCount) {
            // 检查是否刚执行过故排
            $hasRecentCheck = Cache::get('check_flag_' . $this->machine->id);

            if ($hasRecentCheck) {
                // 故排后归零，正常
                $this->log->info("[{$type}计数器] 故排后归零", [
                    'machine_code' => $this->machine->code,
                    'old' => $oldCount,
                    'new' => $newCount,
                ]);
                return ['should_update' => true, 'recorded' => false, 'reason' => '故排归零'];
            } else {
                // 异常减少，告警
                $this->log->error("[{$type}计数器] 异常减少", [
                    'machine_code' => $this->machine->code,
                    'old' => $oldCount,
                    'new' => $newCount,
                    'decrease' => $oldCount - $newCount,
                ]);

                // 发送机台异常通知
                try {
                    sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, 0);
                } catch (Exception $e) {
                    $this->log->error('发送机台异常通知失败', ['error' => $e->getMessage()]);
                }

                return ['should_update' => true, 'recorded' => false, 'reason' => '异常减少'];
            }
        }

        // ========== 情况2：计数器不变 ==========
        if ($newCount == $oldCount) {
            return ['should_update' => false, 'recorded' => false, 'reason' => '无变化'];
        }

        // ========== 情况3：计数器增加 ==========
        $increment = $newCount - $oldCount;

        // ✅ P1-4修复：使用 Cache 存储去重时间，支持跨请求去重
        $cacheKey = "last_counter_update_{$this->machine->id}_{$type}";
        $lastUpdate = Cache::get($cacheKey, 0);
        if ($now - $lastUpdate < 2) {
            $this->log->warning("[{$type}计数器] 短时间内重复心跳，跳过", [
                'machine_code' => $this->machine->code,
                'interval' => $now - $lastUpdate,
                'increment' => $increment,
            ]);
            return ['should_update' => false, 'recorded' => false, 'reason' => '重复心跳'];
        }

        // 异常增量检查（单次心跳增量不应超过20）
        if ($increment > 20) {
            $this->log->warning("[{$type}计数器] 单次增量异常", [
                'machine_code' => $this->machine->code,
                'increment' => $increment,
                'old' => $oldCount,
                'new' => $newCount,
            ]);
            // 仍然记录，但记录警告
        }

        // ✅ P0-6修复：记录操作（捕获异常，防止 Redis-数据库不一致）
        try {
            $this->recordExternalButtonOperation($type, $increment);
            $recorded = true;
            $reason = '正常增量';

            // ✅ P1-4修复：只有数据库记录成功才更新去重时间（5秒有效期）
            Cache::set($cacheKey, $now, 5);

        } catch (Exception $e) {
            $this->log->error("[{$type}计数器] 数据库记录失败，不更新Redis", [
                'machine_code' => $this->machine->code,
                'increment' => $increment,
                'error' => $e->getMessage(),
            ]);
            $recorded = false;
            $reason = '数据库记录失败';
        }

        return [
            'should_update' => $recorded,  // ✅ 只有数据库成功才允许更新 Redis
            'recorded' => $recorded,
            'reason' => $reason
        ];
    }

    /**
     * 记录外部按钮开洗分操作
     *
     * ⚠️ P1-7修复：移除内层锁（外层 handleExternalButtonData 已有锁保护）
     *
     * @param string $type 类型：open=开分, wash=洗分
     * @param int $times 次数（每次固定100分）
     * @throws Exception 数据库操作失败时抛出异常
     */
    private function recordExternalButtonOperation(string $type, int $times): void
    {
        if ($times <= 0) {
            return;
        }

        // 每次固定100分（机台分数）
        $machinePointPerTime = 100;
        $totalMachinePoint = $machinePointPerTime * $times;

        // 根据机台比值转换成玩家钱包分数
        // 公式：walletAmount = machinePoint / odds_y * odds_x
        if (!$this->machine->odds_y || $this->machine->odds_y <= 0) {
            $this->log->error('[外部按钮] 机台odds_y无效，无法记录', [
                'machine_code' => $this->machine->code,
                'odds_y' => $this->machine->odds_y,
            ]);
            throw new Exception("机台 {$this->machine->code} 的 odds_y 无效");
        }

        $walletAmount = floor($totalMachinePoint / $this->machine->odds_y * $this->machine->odds_x);

        // 获取当前游戏玩家（可能为空）
        $gamingUserId = $this->gaming_user_id ?? 0;
        $player = null;

        if ($gamingUserId > 0) {
            $player = Player::with(['recommend_promoter', 'recommend_promoter.national_promoter'])
                ->find($gamingUserId);
        }

        // ✅ 创建 PlayerGameLog（带事务保护）
        // ⚠️ P0-6修复：如果失败会抛出异常，不再吞掉
        $this->createExternalButtonGameLog($type, $totalMachinePoint, $walletAmount, $player);

        $this->log->info('[外部按钮] 记录开洗分操作', [
            'machine_code' => $this->machine->code,
            'type' => $type,
            'times' => $times,
            'machine_point' => $totalMachinePoint,
            'wallet_amount' => $walletAmount,
            'player_id' => $gamingUserId,
            'has_player' => !empty($player),
        ]);
    }

    /**
     * 创建外部按钮游戏日志（带事务保护）
     *
     * @param string $type 类型：open=开分, wash=洗分
     * @param int $machinePoint 机台分数
     * @param float $walletAmount 钱包金额
     * @param Player|null $player 玩家（可能为空）
     * @throws Exception
     */
    private function createExternalButtonGameLog(string $type, int $machinePoint, float $walletAmount, ?Player $player): void
    {
        // ✅ 使用事务保护
        \support\Db::beginTransaction();

        try {
            $playerGameLog = new \app\model\PlayerGameLog();

            // 玩家信息（可能为0）
            $playerGameLog->player_id = $player->id ?? 0;
            $playerGameLog->parent_player_id = $player->recommend_id ?? 0;

            // 渠道ID和门店ID：有玩家时从player获取，无玩家时从ChannelMachine获取
            if ($player) {
                // 有玩家：从玩家获取
                $playerGameLog->department_id = $player->department_id;
                $playerGameLog->store_id = $player->store_admin_id;
                $playerGameLog->store_agent_id = $player->agent_admin_id;
            } else {
                // 无玩家：从机台绑定获取
                $channelMachine = \app\model\ChannelMachine::where('machine_id', $this->machine->id)->first();
                if ($channelMachine) {
                    $playerGameLog->department_id = $channelMachine->department_id;
                    $playerGameLog->store_id = $channelMachine->store_admin_id;

                    // 门店代理ID：从门店的上级获取
                    if ($channelMachine->store_admin_id) {
                        $storeAdmin = AdminUser::find($channelMachine->store_admin_id);
                        $playerGameLog->store_agent_id = $storeAdmin->parent_admin_id ?? null;
                    }
                }
            }

            // 玩家代理信息
            $playerGameLog->agent_player_id = $player->recommend_promoter?->recommend_id ?? 0;

            // 机台信息
            $playerGameLog->game_id = $this->machine->machineCategory?->game_id ?? 0;
            $playerGameLog->machine_id = $this->machine->id;
            $playerGameLog->type = $this->machine->type;
            $playerGameLog->odds = $this->machine->odds_x . ':' . $this->machine->odds_y;
            $playerGameLog->control_open_point = $this->machine->control_open_point ?? 0;

            // 操作类型和分数
            if ($type === 'open') {
                $playerGameLog->action = \app\model\PlayerGameLog::ACTION_OPEN;
                $playerGameLog->open_point = $machinePoint;
                $playerGameLog->wash_point = 0;
            } else {
                $playerGameLog->action = \app\model\PlayerGameLog::ACTION_DOWN;
                $playerGameLog->open_point = 0;
                $playerGameLog->wash_point = $machinePoint;
            }

            // 金额信息（✅ 如果有玩家，读取实际余额）
            $playerGameLog->gift_point = 0;
            $playerGameLog->game_amount = $walletAmount;

            if ($player) {
                try {
                    $currentBalance = \app\service\WalletService::getBalance($player->id);
                    $playerGameLog->before_game_amount = $currentBalance;
                    $playerGameLog->after_game_amount = $currentBalance; // 外部按钮不扣款，余额不变
                } catch (Exception $e) {
                    $this->log->warning('[外部按钮] 获取玩家余额失败，使用0', [
                        'player_id' => $player->id,
                        'error' => $e->getMessage(),
                    ]);
                    $playerGameLog->before_game_amount = 0;
                    $playerGameLog->after_game_amount = 0;
                }
            } else {
                $playerGameLog->before_game_amount = 0;
                $playerGameLog->after_game_amount = 0;
            }

            $playerGameLog->machine_amount = 0;

            // ⚠️ 关键：标记为外部按钮来源
            $playerGameLog->source_type = \app\model\PlayerGameLog::SOURCE_TYPE_OFFLINE_BUTTON;

            // 游戏记录ID（尝试关联，可能为null）
            $gameRecordId = $this->getOrCreateGameRecordId($player);

            // ✅ P0-20修复：game_record_id 可能为 null（无玩家时），设置为 0
            // 数据库 game_record_id 字段不允许 NULL，0 表示无关联游戏记录
            $playerGameLog->game_record_id = ($gameRecordId !== null) ? $gameRecordId : 0;

            // 管理员信息（外部按钮无管理员）
            $playerGameLog->user_id = 0;
            $playerGameLog->user_name = '';
            $playerGameLog->is_system = 0;
            $playerGameLog->is_test = $player->is_test ?? 0;

            $playerGameLog->save();

            // ✅ 更新 PlayerGameRecord：累加金额并标记（使用行锁防止并发丢失数据）
            if ($gameRecordId > 0) {
                // ⚠️ P0-8修复：使用 lockForUpdate 并检查 status，防止更新到已结束的游戏记录
                $gameRecord = \app\model\PlayerGameRecord::query()
                    ->where('id', $gameRecordId)
                    ->where('status', \app\model\PlayerGameRecord::STATUS_START)  // ✅ 必须再次检查状态
                    ->lockForUpdate()  // SELECT ... FOR UPDATE
                    ->first();

                if ($gameRecord) {
                    // 累加机台分数和钱包金额
                    if ($type === 'open') {
                        $gameRecord->open_point = bcadd($gameRecord->open_point, $machinePoint, 2);
                        $gameRecord->open_amount = bcadd($gameRecord->open_amount, $walletAmount, 2);
                    } else {
                        $gameRecord->wash_point = bcadd($gameRecord->wash_point, $machinePoint, 2);
                        $gameRecord->wash_amount = bcadd($gameRecord->wash_amount, $walletAmount, 2);
                    }

                    // 标记包含实体按键操作
                    $gameRecord->has_external_button = 1;

                    // ⚠️ 注意：外部按钮不改变钱包余额，所以 after_game_amount 不更新
                    // 实际钱包余额在玩家通过系统开洗分时才变化

                    $gameRecord->save();

                    $this->log->info('[外部按钮] 更新 PlayerGameRecord', [
                        'game_record_id' => $gameRecordId,
                        'type' => $type,
                        'machine_point' => $machinePoint,
                        'wallet_amount' => $walletAmount,
                        'updated_open_point' => $gameRecord->open_point,
                        'updated_wash_point' => $gameRecord->wash_point,
                    ]);
                } else {
                    // ✅ P0-8修复：游戏记录已结束或不存在，不累加（只记录日志）
                    $this->log->warning('[外部按钮] 游戏记录已结束或不存在，跳过更新 PlayerGameRecord', [
                        'game_record_id' => $gameRecordId,
                        'type' => $type,
                        'machine_point' => $machinePoint,
                        'wallet_amount' => $walletAmount,
                    ]);
                }
            }

            \support\Db::commit();

            $this->log->info('[外部按钮] 创建游戏日志', [
                'player_game_log_id' => $playerGameLog->id,
                'player_id' => $playerGameLog->player_id,
                'machine_id' => $playerGameLog->machine_id,
                'type' => $type,
                'machine_point' => $machinePoint,
                'wallet_amount' => $walletAmount,
                'game_record_id' => $gameRecordId,
            ]);

        } catch (Exception $e) {
            \support\Db::rollBack();
            $this->log->error('[外部按钮] 创建游戏日志失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // ✅ 抛出异常，让上层处理
        }
    }

    /**
     * 获取或创建游戏记录ID
     *
     * @param Player|null $player
     * @return int|null
     */
    private function getOrCreateGameRecordId(?Player $player): ?int
    {
        if (!$player) {
            return null; // 无玩家时返回null
        }

        try {
            // 查找当前玩家在该机台的进行中游戏记录
            $gameRecord = \app\model\PlayerGameRecord::query()
                ->where('machine_id', $this->machine->id)
                ->where('player_id', $player->id)
                ->where('status', \app\model\PlayerGameRecord::STATUS_START)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($gameRecord) {
                // ✅ 直接返回ID，更新操作在 createExternalButtonGameLog() 中统一处理
                return $gameRecord->id;
            }

            // ⚠️ 没有进行中的记录，不自动创建（外部按钮操作不应该创建新游戏记录）
            return null;

        } catch (Exception $e) {
            $this->log->error('[外部按钮] 获取游戏记录失败', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 处理心跳数据（支持36字符标准心跳和14字符短心跳）
     *
     * ✅ P0-18修复：支持14字符短心跳（仅在线检测，不解析详细数据）
     * @throws Exception
     */
    private function handleHeartbeat(string $msg, int $gamingUserId, int $orgRewardStatus, int $orgWinNumber, float $orgTurn): bool
    {
        $len = strlen($msg);

        // ========== 短心跳（14字符）：仅在线检测 ==========
        if ($len < 36) {
            $this->log->info('[心跳] 收到短心跳，跳过详细解析', [
                'machine_code' => $this->machine->code,
                'heartbeat_length' => $len,
                'heartbeat' => $msg,
                'player_id' => $gamingUserId,
            ]);

            // 短心跳只维持在线状态，不更新详细数据
            // 详细数据从Redis缓存读取（保持上次标准心跳的状态）
            return true;
        }

        // ========== 标准心跳（36字符）：完整解析 ==========

        // 检查机台状态（第18-19字节必须是 DA=正常）
        if (substr($msg, 18, 2) != 'da') {
            $this->has_lock = 1;
            sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
            throw new Exception('机台故障');
        }

        // 解析心跳数据
        [$nowPoint, $nowRatio, $nowWinNumber, $nowScore, $nowTurn] = self::parseHeartbeat($msg);
        $nowAuto = (substr($msg, 2, 2) == 'c6') ? 1 : 0;
        $nowRewardStatus = (substr($msg, 10, 2) == 'd5') ? 1 : 0; // D5=开奖中

        // ✅ 记录机台实时状态日志（用于监控和调试）
        $this->log->info('[机台实时状态] 心跳数据解析', [
            'machine_code' => $this->machine->code,
            'player_id' => $gamingUserId,
            'status' => [
                '自动状态' => $nowAuto ? '启动中' : '停止中',
                '开奖状态' => $nowRewardStatus ? '开奖中' : '未开奖',
                '分数' => $nowPoint,
                '得分(WIN)' => $nowScore,
                '剩余转数' => $nowTurn,
                '累积转数' => $nowWinNumber,
                '扣趴比例' => $nowRatio . '%',
            ],
            'changes' => [
                '分数变化' => ($nowPoint - ($this->point ?? 0)),
                '得分变化' => ($nowScore - ($this->score ?? 0)),
                '转数变化' => ($nowTurn - $orgTurn),
                '累积转数变化' => ($nowWinNumber - $orgWinNumber),
            ],
            'external_buttons' => [
                '开分次数' => $this->external_open_count ?? 0,
                '洗分次数' => $this->external_wash_count ?? 0,
            ],
            'raw_data' => $msg,
        ]);

        // 更新Redis状态
        $this->point = $nowPoint;
        $this->auto = $nowAuto;
        $this->win_number = $nowWinNumber;
        $this->score = $nowScore;
        $this->turn = $nowTurn;
        $this->reward_status = $nowRewardStatus;
        $this->now_turn = $nowWinNumber;
        $this->ratio = $nowRatio;

        // ✅ 设置查询指令的actionVersion（心跳包含所有查询数据，可作为查询响应）
        // 这样发送查询指令后，收到心跳也能被识别为有效响应
        $this->setActionVersion(self::MACHINE_POINT);   // 分数查询
        $this->setActionVersion(self::MACHINE_SCORE);   // 得分查询
        $this->setActionVersion(self::MACHINE_TURN);    // 转数查询
        $this->setActionVersion(self::WIN_NUMBER);      // 累积转数查询

        // ==================== 开奖开始 ====================
        if ($nowRewardStatus == 1 && $orgRewardStatus == 0) {
            $machineLotteryRecord = new MachineLotteryRecord();
            $machineLotteryRecord->machine_id = $this->machine->id;
            $machineLotteryRecord->player_id = $gamingUserId;
            $machineLotteryRecord->department_id = $this->machine->gamingPlayer->department_id ?? 0;
            $machineLotteryRecord->draw_bet = $this->win_number;
            $machineLotteryRecord->use_turn = $this->now_turn;
            $machineLotteryRecord->save();
        }

        // ==================== 开奖结束 ====================
        if ($nowRewardStatus == 0 && $orgRewardStatus == 1) {
            // 触发彩池抽奖
            if (!empty($this->machine->gamingPlayer)) {
                (new LotteryServices())
                    ->setMachine($this->machine)
                    ->setPlayer($this->machine->gamingPlayer)
                    ->fixedPotCheckLottery($nowScore);
            }

            // 投递活动队列
            if ($nowScore > 0 && !empty($gamingUserId)) {
                Client::send('play-activity', [
                    'machine_id' => $this->machine->id,
                    'player_id' => $gamingUserId,
                    'point' => $nowScore,
                ]);

                // 钢珠报喜：检测珠数是否达到阈值并广播
                \app\service\SteelBallBroadcastService::checkAndBroadcast($this->machine, $nowScore);
            }

            // 通知前端开奖结束
            sendSocketMessage('group-' . $this->machine->id, [
                'msg_type' => 'machine_reward_end',
                'machine_id' => $this->machine->id,
                'machine_code' => $this->machine->code,
                'gaming_user_id' => $gamingUserId,
            ]);

            // 自动执行"得分→分数"转换
            $this->sendCmd(self::SCORE_TO_POINT, 0, 'player', $gamingUserId);
        }

        // ==================== 转数变化处理（线下版简化逻辑）====================
        // 线下版不需要复杂的保留机制，只累计消耗的转数用于打码量统计
        if ($nowRewardStatus == 0 && !empty($gamingUserId)) {
            $turnDelta = bcsub($nowTurn, $orgTurn, 2);

            // 负增量说明玩家消耗了转数（正常游玩）
            if (bccomp($turnDelta, '0', 2) < 0 && bccomp($turnDelta, '-10', 2) >= 0) {
                $consumed = abs($turnDelta);

                // 累加到打码量统计
                $cateId = $this->machine->cate_id;
                $turnUsedPointCacheKey = "machine_category:{$cateId}:turn_used_point";
                $turnUsedPoint = \support\Cache::get($turnUsedPointCacheKey);

                if ($turnUsedPoint === null) {
                    $turnUsedPoint = \app\model\MachineCategory::query()
                        ->where('id', $cateId)
                        ->value('turn_used_point') ?? 0;
                    \support\Cache::set($turnUsedPointCacheKey, $turnUsedPoint, 3600);
                }

                $betAmount = bcmul($consumed, $turnUsedPoint, 2);

                if (bccomp($betAmount, '0', 2) > 0) {
                    Log::channel('bet_statistics')->info('[BetStats] SongOfflineJackpot 投递打码量', [
                        'machine_id' => $this->machine->id,
                        'player_id' => $gamingUserId,
                        'consumed_turn' => $consumed,
                        'turn_used_point' => $turnUsedPoint,
                        'bet_amount' => floatval($betAmount),
                    ]);

                    Client::send('bet-statistics', [
                        'player_id' => $gamingUserId,
                        'stat_type' => 'machine',
                        'bet_amount' => floatval($betAmount),
                        'source' => 'song_offline_jackpot',
                        'machine_id' => $this->machine->id,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                // 更新最后游戏时间
                $this->last_play_time = time();
            }
        }

        // 推送机台状态到前端
        $this->sendMachineNowStatusMessage($this->machine->id);
        return true;
    }

    /**
     * 处理指令回复
     */
    private function handleCommandReply(string $msg, string $fun, string $fun1, int $gamingUserId): bool
    {
        switch ($fun) {
            // 确认类指令（只更新版本号）
            case self::REWARD_SWITCH:
            case self::MACHINE_OPEN:
            case self::MACHINE_CLOSE:
            case self::TURN_DOWN_ALL:
            case self::TURN_UP_ALL:
            case self::PUSH_THREE:
            case self::PUSH_ONE:
            case self::CLEAR_LOG:
            case self::POINT_TO_TURN:
            case self::TURN_TO_POINT:
                $this->setActionVersion($fun);
                break;

            case self::CHECK:
                // ✅ 设置故排标记（用于计数器归零检测）
                Cache::set('check_flag_' . $this->machine->id, true, 10); // 10秒有效

                // ✅ 故排指令：清除外部按钮开洗分次数（协议规定）
                $oldOpenCount = $this->external_open_count ?? 0;
                $oldWashCount = $this->external_wash_count ?? 0;

                $this->external_open_count = 0;
                $this->external_wash_count = 0;
                $this->setActionVersion($fun);

                $this->log->info('[故排] 清除外部按钮计数器', [
                    'machine_code' => $this->machine->code,
                    'old_open_count' => $oldOpenCount,
                    'old_wash_count' => $oldWashCount,
                    'note' => '故排指令会清除 B5/B7 计数器，已设置10秒标记'
                ]);
                break;

            case self::AUTO_UP_TURN:
                $this->auto = 1;
                $this->setActionVersion($fun);
                break;

            case self::AUTO_STOP:
                $this->auto = 0;
                $this->setActionVersion($fun);
                break;

            // ✅ P0-14修复：SCORE_TO_POINT 是6位指令，需要用 $fun 匹配
            case self::SCORE_TO_POINT:
                $this->setActionVersion(self::SCORE_TO_POINT);
                break;

            default:
                return $this->handleActionReply($msg, $fun1, $gamingUserId);
        }

        return true;
    }

    /**
     * 处理动作回复（查询、上下分等）
     *
     * ✅ P0-19修复：添加详细日志
     */
    private function handleActionReply(string $msg, string $action, int $gamingUserId): bool
    {
        // ✅ 记录进入handleActionReply
        $this->log->debug('[动作回复] 处理机台动作回复', [
            'machine_code' => $this->machine->code,
            'action' => $action,
            'msg' => $msg,
            'msg_length' => strlen($msg),
        ]);

        switch ($action) {
            // 上分回复
            case self::OPEN_ANY_POINT:
                Redis::publish($this->machine->domain . ':' . $this->machine->port, '设备返回的消息');
                $this->setActionVersion(substr($msg, 0, 6));
                break;

            // 故障码
            case self::FAULT1_MACHINE_SCORE:
            case self::FAULT_MACHINE_SCORE:
                $this->has_lock = 1;
                $faultCode = ($action == self::FAULT1_MACHINE_SCORE) ? 'FAULT1' : 'FAULT2';
                Log::channel('machine_operations')->error('[SongOfflineJackpot-MachineLock] 机台被锁', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'lock_reason' => '机台报告故障',
                    'fault_code' => $faultCode,
                    'msg' => $msg,
                ]);
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $gamingUserId);
                throw new Exception('机台故障');

            // 下分回复（三次握手）
            case self::WASH_ZERO:
                Redis::publish($this->machine->domain . ':' . $this->machine->port, '设备返回的消息');
                $cmd = substr($msg, 0, 6);

                // ✅ 调试日志：记录下分回复
                $this->log->info('[下分回复] 收到机台下分回复', [
                    'machine_code' => $this->machine->code,
                    'full_msg' => $msg,
                    'msg_length' => strlen($msg),
                    'extracted_cmd' => $cmd,
                    'action' => 'WASH_ZERO',
                ]);

                $this->setActionVersion($cmd);
                $s1 = substr($msg, -4, 2);
                $s2 = substr($msg, -2, 2);
                $uid = $this->machine->domain . ':' . $this->machine->port;
                Gateway::sendToUid($uid, hex2bin($cmd . $s1 . $s2));

                $this->log->info('[下分回复] 发送第二次握手', [
                    'machine_code' => $this->machine->code,
                    'send_cmd' => $cmd . $s1 . $s2,
                ]);
                break;

            // 查询分数响应
            case self::GET_MACHINE_POINT:   // 46c0
            case self::AUTO_MACHINE_POINT:  // 46c6
                $point = self::parseScore(substr($msg, 4, 6));
                $this->point = $point;
                $this->setActionVersion(self::MACHINE_POINT);
                break;

            // 查询得分响应
            case self::GET_MACHINE_SCORE:  // 46da
                $score = self::parseScore(substr($msg, 4, 6));
                $this->score = $score;
                $this->setActionVersion(self::MACHINE_SCORE);
                break;

            // 查询转数响应
            case self::GET_MACHINE_TURN:  // 46de
                $turn = self::parseScore('00' . substr($msg, 4, 4));
                $this->turn = $turn;
                $this->setActionVersion(self::MACHINE_TURN);
                break;

            // 查询累积转数响应（文档：46 D0 XX xx xx，3字节数据）
            case self::GET_WIN_NUMBER:     // 46d0
            case self::REWARD_WIN_NUMBER:  // 46d5
                // ✅ 修复：查询响应是3字节（位置4-9），不是2字节
                // 文档：46 D0 XX xx xx S1 S2，数据从位置4开始，共6个字符（3字节）
                $winNumber = self::parseScore(substr($msg, 4, 6));
                $oldWinNumber = $this->win_number;
                $delta = $winNumber - $oldWinNumber;

                // 防止异常值
                if (abs($delta) > 100) {
                    $this->log->error('检测到异常的winNumber值，拒绝更新', [
                        'machine_code' => $this->machine->code,
                        'old_win_number' => $oldWinNumber,
                        'new_win_number' => $winNumber,
                        'delta' => $delta,
                    ]);
                } else {
                    $this->win_number = $winNumber;
                }
                $this->setActionVersion(self::WIN_NUMBER);
                break;

            // ✅ P0-14修复：SCORE_TO_POINT 已移至 handleCommandReply()（6位指令用$fun匹配）

            default:
                throw new Exception('不存在的指令: ' . $action);
        }

        return true;
    }

    /**
     * 计算S1校验位 (XOR异或校验)
     * @param string $data 指令数据（不含S1/S2）
     * @return string 16进制的S1校验位
     */
    public static function calculateS1(string $data): string
    {
        $bytes = str_split($data, 2);
        $xor = 0;
        foreach ($bytes as $byte) {
            $xor ^= hexdec($byte);
        }
        return str_pad(dechex($xor), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 计算S2校验位 (ADD累加校验)
     * @param string $data 指令数据（不含S1/S2）
     * @param string $s1 计算出的S1值
     * @return string 16进制的S2校验位（取最后2位）
     */
    public static function calculateS2(string $data, string $s1): string
    {
        $bytes = str_split($data, 2);
        $add = 0;
        foreach ($bytes as $byte) {
            $add += hexdec($byte);
        }
        $add += hexdec($s1);
        $result = $add & 0xFF;
        return str_pad(dechex($result), 2, '0', STR_PAD_LEFT);
    }

    /**
     * 解析心跳指令中的数据
     *
     * 格式（36字节）：
     * 46 C0 05 14 19 D0 03 0A 0F DA 00 00 00 DE 00 00 (S1) (S2)
     * │  │  └─┴─┴── │  │  └─┴── │  └─┴─┴── │  └─┴──
     * │  │  分数    │  扣 转数  │  得分    │  剩余转数
     * │  │          │  趴      │          │
     * │  └─ 状态    └─ 旗标    └─ 机台状态
     * │     C0=停止
     * │     C6=启动
     * └─ 分机号
     *
     * @param string $command 心跳指令（36字节）
     * @return array [分数, 扣趴, 累积转数, 得分, 剩余转数]
     */
    public static function parseHeartbeat(string $command): array
    {
        $cleanCommand = str_replace(' ', '', strtoupper(trim($command)));

        $parts = [
            'point_section' => substr($cleanCommand, 4, 6),    // 字节3-5: 当前分数
            'ratio_section' => substr($cleanCommand, 12, 2),   // 字节6: 扣趴
            'win_number_section' => substr($cleanCommand, 14, 4), // 字节7-8: 累积转数
            'score_section' => substr($cleanCommand, 20, 6),   // 字节10-12: 得分
            'turn_section' => substr($cleanCommand, 28, 4)     // 字节14-15: 剩余转数
        ];

        // 扣趴对照表（协议定义）
        $ratioArr = [
            '00' => '10', '01' => '11', '02' => '12',
            '03' => '13', '04' => '14', '05' => '15',
        ];

        // ✅ 扣趴异常值处理（默认10%）
        $ratioCode = $parts['ratio_section'];
        $ratio = $ratioArr[$ratioCode] ?? null;

        if ($ratio === null) {
            // 记录异常扣趴码
            Log::channel('machine_operations')->warning('[SongOfflineJackpot] 收到未定义的扣趴码', [
                'ratio_code' => $ratioCode,
                'default_to' => '10%'
            ]);
            $ratio = '10'; // 默认10%
        }

        return [
            self::parseScore($parts['point_section']),
            $ratio,
            self::parseScore('00' . $parts['win_number_section']),
            self::parseScore($parts['score_section']),
            self::parseScore('00' . $parts['turn_section']),
        ];
    }

    /**
     * 解析BCD编码的分数
     *
     * 格式: xx yy zz
     * 例: 01 05 1E → 10000 + 500 + 30 = 10530
     */
    private static function parseScore($scoreSection): float|int
    {
        $bytes = str_split($scoreSection, 2);
        $bcd2 = $bytes[0]; // 万位
        $bcd1 = $bytes[1]; // 千百位
        $bcd0 = $bytes[2]; // 十个位
        return (hexdec($bcd2) * 10000) + (hexdec($bcd1) * 100) + hexdec($bcd0);
    }

    /**
     * 设置操作版本号（用于异步指令确认）
     */
    public function setActionVersion($name): float
    {
        $version = getMillisecond();
        Cache::set($this->cacheDataKey . '_' . 'action_' . $name, $version, 60 * 60);
        return $version;
    }

    /**
     * 发送指令到硬件
     *
     * @param string $cmd 指令码
     * @param int $data 数据（分数）
     * @param string $source 来源（player/admin/system）
     * @param int $source_id 来源ID
     * @param int $isSystem 是否系统操作
     * @return bool
     * @throws Exception
     * @throws PushException
     */
    public function sendCmd(
        string $cmd,
        int $data = 0,
        string $source = 'player',
        int $source_id = 0,
        int $isSystem = 0
    ): bool
    {
        $uid = $this->machine->domain . ':' . $this->machine->port;

        try {
            // 🔍 DEBUG: sendCmd 调用诊断
            $this->log->info('[sendCmd] 开始执行指令', [
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'data' => $data,
                'source' => $source,
                'source_id' => $source_id,
                'uid' => $uid,
            ]);

            // 检查设备在线
            $isOnline = Gateway::isUidOnline($uid);
            if (!$isOnline) {
                $this->log->error('[sendCmd] 设备离线', [
                    'machine_code' => $this->machine->code,
                    'uid' => $uid,
                    'cmd' => $cmd,
                ]);
                throw new Exception(trans('machine_has_offline', ['{code}' => $this->machine->code], 'message'));
            }

            // 检查机台锁定
            if ($this->has_lock == 1 && $cmd != self::CHECK) {
                $this->log->error('[sendCmd] 机台已锁定', [
                    'machine_code' => $this->machine->code,
                    'has_lock' => $this->has_lock,
                    'cmd' => $cmd,
                ]);
                throw new Exception(trans('machine_lock', ['{code}' => $this->machine->code], 'message'));
            }

            $this->log->info('[sendCmd] 检查通过，准备发送指令', [
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'is_online' => true,
                'is_locked' => false,
            ]);

            // ⚠️ 玩家操作时立即更新活动时间
            if ($source == 'player') {
                $currentGamingUserId = $this->gaming_user_id;
                if (!empty($currentGamingUserId)) {
                    $this->last_play_time = time();
                }
            }

            switch ($cmd) {
                case self::SCORE_TO_POINT:
                    if ($this->reward_status == 1) {
                        throw new Exception(trans('machine_reward_drawing', ['{code}' => $this->machine->code], 'message'));
                    }
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;

                case self::TURN_UP_ALL:
                    if ($this->point < 100) {
                        throw new Exception(trans('machine_point_insufficient', ['{code}' => $this->machine->code], 'message'));
                    }
                    // ✅ 验证转数上限（协议限制：980转）
                    $currentTurn = $this->turn ?? 0;
                    if ($currentTurn >= self::MAX_TURN) {
                        throw new Exception("转数已达上限({$currentTurn}/{self::MAX_TURN})，无法继续上转");
                    }
                    Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
                    break;

                case self::POINT_TO_TURN:
                    // ✅ 单次上转也检查上限
                    $currentTurn = $this->turn ?? 0;
                    if ($currentTurn >= self::MAX_TURN) {
                        throw new Exception("转数已达上限({$currentTurn}/{self::MAX_TURN})，无法继续上转");
                    }
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;

                case self::TURN_DOWN_ALL:
                case self::TURN_TO_POINT:
                case self::MACHINE_SCORE:
                case self::MACHINE_POINT:
                case self::MACHINE_TURN:
                case self::WIN_NUMBER:
                    $this->machineAction($uid, $cmd, $source, $source_id);
                    break;

                case self::OPEN_ANY_POINT:
                    $code = sprintf('%02x', rand(0, 0x63));
                    $this->openPoint($uid, $cmd . $code, $data, $source, $source_id);
                    break;

                case self::WASH_ZERO:
                    $code = sprintf('%02x', rand(0, 0x63));
                    $this->washPoint($uid, $cmd . $code, $data, $source, $source_id);
                    break;

                case self::AUTO_UP_TURN:
                    if ($this->reward_status == 1) {
                        throw new Exception(trans('machine_reward_drawing', ['{code}' => $this->machine->code], 'message'));
                    }
                    if ($this->score > 0) {
                        throw new Exception(trans('machine_sore_exist', ['{code}' => $this->machine->code, '{score}' => $this->score], 'message'));
                    }
                    $auto = $this->auto;
                    if ($auto == 1) {
                        Gateway::sendToUid($uid, hex2bin($this->createCmd(self::AUTO_STOP)));
                    } else {
                        Gateway::sendToUid($uid, hex2bin($this->createCmd(self::AUTO_UP_TURN)));
                    }
                    break;

                default:
                    Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
                    break;
            }
        } catch (Exception $e) {
            if (in_array($cmd, [self::OPEN_ANY_POINT, self::WASH_ZERO])) {
                $this->has_lock = 1;
                Log::channel('machine_operations')->error('[SongOfflineJackpot-MachineLock] 机台被锁', [
                    'machine_id' => $this->machine->id,
                    'machine_code' => $this->machine->code,
                    'cmd' => $cmd,
                    'lock_reason' => '指令执行异常',
                    'exception_message' => $e->getMessage(),
                ]);
                sendMachineException($this->machine, Notice::TYPE_MACHINE_LOCK, $this->gaming_user_id);
            }
            throw new Exception($e->getMessage(). $cmd);
        }

        if ($source == 'admin') {
            sendSocketMessage('private-admin-1-' . $source_id, [
                'msg_type' => 'machine_action_result',
                'id' => $this->machine->id,
                'description' => $this->getDescription($cmd),
            ]);
        }

        $this->log->info('[' . ($source === 'admin' ? '管理员操作' : '玩家操作') . '] 机台操作', [
            'operator_type' => $source,
            'operator_id' => $source_id,
            'machine_code' => $this->machine->code,
            'action' => $cmd,
            'point' => $data,
        ]);

        return true;
    }

    /**
     * 机台动作（带重试）
     */
    private function machineAction(
        string $uid,
        string $cmd,
        string $source = 'player',
        int $source_id = 0,
        int $attempts = 0
    ): void
    {
        $maxRetries = 8;
        $expirationTime = 1000000; // 1秒

        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd)));

            $handleDuration = 0;
            $sleep = 5000; // 5ms

            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                if ($actionTime > $beforeActionTime) {
                    return;
                }
                if ($handleDuration >= $expirationTime) {
                    throw new Exception(trans('machine_action_fail', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception $e) {
            $attempts++;
            if ($attempts >= $maxRetries) {
                throw new Exception(trans('machine_action_fail', [], 'message'));
            }
            usleep(50000);
            $this->machineAction($uid, $cmd, $source, $source_id, $attempts);
        }
    }

    /**
     * 创建指令（添加S1/S2校验）
     *
     * ✅ 支持动态分机号：将指令常量中的前2位替换为实际分机号
     */
    private function createCmd(string $cmd, mixed $data = 0): string
    {
        // ✅ 替换分机号（指令常量硬编码为46，需要替换为实际分机号）
        if (strlen($cmd) >= 2 && $this->extensionNumber !== '46') {
            $cmd = $this->extensionNumber . substr($cmd, 2);
        }

        $hexString = '';
        if (!empty($data)) {
            $bytes = $this->scoreToBytes($data);
            $hexString = $this->toHexString($bytes);
        }
        $cmd .= $hexString;
        $s1 = self::calculateS1($cmd);
        $s2 = self::calculateS2($cmd, $s1);
        $this->log->info('发送指令', [
            $cmd . $s1 . $s2
        ]);
        return $cmd . $s1 . $s2;
    }

    /**
     * 将分数转换为3个BCD字节
     */
    public static function scoreToBytes(int $score): array
    {
        $score = max(0, min(99999, $score));
        $tenThousands = intval($score / 10000);
        $thousandsHundreds = intval(($score % 10000) / 100);
        $tensOnes = $score % 100;
        return [$tenThousands, $thousandsHundreds, $tensOnes];
    }

    /**
     * 字节数组转十六进制字符串
     */
    public static function toHexString($bytes): string
    {
        return implode('', array_map(function ($b) {
            return strtoupper(str_pad(dechex($b), 2, '0', STR_PAD_LEFT));
        }, $bytes));
    }

    /**
     * 获取操作版本号
     */
    public function getActionVersion($name): float
    {
        return (float)Cache::get($this->cacheDataKey . '_' . 'action_' . $name);
    }

    /**
     * 获取机台描述
     */
    public function getDescription(string $fun = ''): string
    {
        locale(Str::replace('-', '_', $this->lang));
        $autoStatus = $this->auto == 1 ? trans('machine_status_yes', [], 'machine_action') : trans('machine_status_no', [], 'machine_action');
        $lotteryStatus = $this->reward_status == 1 ? trans('machine_status_yes', [], 'machine_action') : trans('machine_status_no', [], 'machine_action');

        if (empty($fun)) {
            $description = trans('machine_auto_status', [], 'machine_action') . $autoStatus . PHP_EOL;
            $description .= trans('machine_lottery_status', [], 'machine_action') . $lotteryStatus . PHP_EOL;
            $description .= trans('machine_point', [], 'machine_action') . ($this->point ?? 0) . PHP_EOL;
            $description .= trans('machine_score', [], 'machine_action') . ($this->score ?? 0) . PHP_EOL;
            $description .= trans('machine_turn', [], 'machine_action') . ($this->turn ?? 0) . PHP_EOL;
            $description .= trans('now_turn', [], 'machine_action') . ($this->now_turn ?? 0) . PHP_EOL;
        } else {
            $description = trans('function.' . GameType::TYPE_STEEL_BALL . '_' . Machine::CONTROL_TYPE_SONG . '.' . $fun, [], 'machine_action');
            switch ($fun) {
                case self::MACHINE_POINT:
                    $description .= ': ' . $this->point;
                    break;
                case self::MACHINE_SCORE:
                    $description .= ': ' . $this->score;
                    break;
                case self::MACHINE_TURN:
                    $description .= ': ' . $this->turn;
                    break;
                case self::WIN_NUMBER:
                    $description .= ': ' . $this->win_number;
                    break;
            }
        }

        return $description;
    }

    /**
     * 上分操作
     */
    private function openPoint(
        string $uid,
        string $cmd,
        int $data,
        string $source = 'player',
        int $source_id = 0,
    ): void
    {
        $expirationTime = 1000000;
        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            Gateway::sendToUid($uid, hex2bin($this->createCmd($cmd, $data)));
            $handleDuration = 0;
            $sleep = 50000;

            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                if ($actionTime > $beforeActionTime) {
                    return;
                }
                if ($handleDuration >= $expirationTime) {
                    throw new Exception(trans('machine_action_fail', [], 'message'));
                }
                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception) {
            throw new Exception(trans('machine_action_fail', [], 'message'));
        }
    }

    /**
     * 洗分操作（三次握手）
     *
     * ✅ P0-19修复：添加详细日志，优化超时处理
     */
    private function washPoint(
        string $uid,
        string $cmd,
        int $data,
        string $source = 'player',
        int $source_id = 0,
        int $attempts = 0
    ): void
    {
        $maxRetries = 8;
        $expirationTime = 2000000; // ✅ 增加到2秒（机台可能响应较慢）

        try {
            $beforeActionTime = $this->setActionVersion($cmd);
            $sendCmd = $this->createCmd($cmd . 'c1', 0);

            // ✅ 详细日志：记录下分请求
            $this->log->info('[下分请求] 发送第一次握手', [
                'machine_code' => $this->machine->code,
                'cmd' => $cmd,
                'full_cmd' => $cmd . 'c1',
                'hex_cmd' => $sendCmd,
                'before_action_time' => $beforeActionTime,
                'attempts' => $attempts,
                'source' => $source,
                'player_id' => $source === 'player' ? $source_id : 0,
            ]);

            Gateway::sendToUid($uid, hex2bin($sendCmd));
            $handleDuration = 0;
            $sleep = 50000; // 50ms检查一次
            $checkCount = 0;

            while (true) {
                $actionTime = $this->getActionVersion($cmd);
                $checkCount++;

                if ($actionTime > $beforeActionTime) {
                    // ✅ 成功收到回复
                    $this->log->info('[下分成功] 收到机台回复', [
                        'machine_code' => $this->machine->code,
                        'cmd' => $cmd,
                        'wait_time_ms' => $handleDuration / 1000,
                        'check_count' => $checkCount,
                        'attempts' => $attempts,
                    ]);
                    return;
                }

                if ($handleDuration >= $expirationTime) {
                    // ✅ 超时日志：记录详细状态
                    $this->log->error('[下分超时] 等待机台回复超时', [
                        'machine_code' => $this->machine->code,
                        'cmd' => $cmd,
                        'wait_time_ms' => $handleDuration / 1000,
                        'check_count' => $checkCount,
                        'attempts' => $attempts,
                        'before_action_time' => $beforeActionTime,
                        'current_action_time' => $actionTime,
                        'cache_key' => $this->cacheDataKey . '_action_' . $cmd,
                    ]);
                    throw new Exception(trans('machine_action_fail', [], 'message'));
                }

                usleep($sleep);
                $handleDuration += $sleep;
            }
        } catch (Exception $e) {
            $attempts++;

            // ✅ 重试日志
            if ($attempts < $maxRetries) {
                $this->log->warning('[下分重试] 准备重试', [
                    'machine_code' => $this->machine->code,
                    'cmd' => $cmd,
                    'attempts' => $attempts,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);
                usleep(100000); // 重试前等待100ms
                $this->washPoint($uid, $cmd, $data, $source, $source_id, $attempts);
            } else {
                // ✅ 最终失败日志
                $this->log->error('[下分失败] 重试次数已用尽', [
                    'machine_code' => $this->machine->code,
                    'cmd' => $cmd,
                    'attempts' => $attempts,
                    'max_retries' => $maxRetries,
                    'final_error' => $e->getMessage(),
                ]);
                throw new Exception(trans('machine_action_fail', [], 'message'));
            }
        }
    }
}
