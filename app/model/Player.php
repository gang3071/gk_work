<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Cache;

/**
 * Class Player
 * @property int id 主键
 * @property int talk_user_id QTalk id
 * @property string line_user_id line userID
 * @property int recommend_id 推荐id
 * @property int agent_admin_id 所属代理后台账号ID（关联admin_users表）
 * @property int store_admin_id 所属店家后台账号ID（关联admin_users表）
 * @property int department_id 部门/渠道id
 * @property string uuid uuid
 * @property int type 类型
 * @property int status 状态
 * @property int is_coin 是否币商
 * @property int is_admin 是否管理员账户
 * @property int is_promoter 是否推广员
 * @property int status_withdraw 帐号状态 1啟用 0停用
 * @property int status_transfer 轉點功能
 * @property int status_open_point 开赠权限
 * @property int status_game_platform 是否开启电子游戏
 * @property int machine_play_num 可遊玩台數
 * @property string phone 手机号
 * @property string name 姓名
 * @property string country_code 手机号国家编号
 * @property string recommended_code 输入推荐吗
 * @property string recommend_code 玩家推荐码
 * @property string password 密码
 * @property string play_password 支付密码
 * @property string player_tag 玩家标签
 * @property string currency 币种
 * @property string flag 标签
 * @property string avatar 头像
 * @property int is_test 是否测试账户 0否 1是
 * @property string remark 玩家备注
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 * @property string switch_shop 商城权限(充值、提现、支付管理功能)
 * @property string status_national 全名代理开关 0关闭1-开启
 * @property string status_reverse_water 电子游戏反水状态 1启用 0停用
 * @property string status_machine 实体机台开关 0关闭1-开启
 * @property string status_offline_open 线下开分
 * @property string status_baccarat 真人百家
 * @property string account 账户
 * @property int player_type 玩家类型
 *
 * @property PlayerExtend player_extend
 * @property PlayerPlatformCash machine_wallet
 * @property Player recommend_player
 * @property PlayerLoginRecord the_last_player_login_record
 * @property PlayerRegisterRecord player_register_record
 * @property Channel channel 渠道
 * @property PlayerRechargeRecord player_recharge_record 充值
 * @property PlayerWithdrawRecord player_withdraw_record 提现
 * @property PlayerPresentRecord present_out 转出
 * @property PlayerPresentRecord present_in 转入
 * @property PlayerPromoter player_promoter 推广员
 * @property PlayerPromoter recommend_promoter 所属推广员
 * @property PlayGameRecord game_record 电子游戏记录
 * @property NationalPromoter national_promoter 全民代理
 * @property PlayerBank bankCard 玩家提现账号
 * @property AdminUser agentAdmin 所属代理后台账号
 * @property AdminUser storeAdmin 所属店家后台账号
 * @package app\model
 */
class Player extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    const STATUS_ENABLE = 1; // 启用状态
    const STATUS_STOP = 0; // 停用状态

    const TYPE_PLAYER = 1; // 普通玩家

    // 玩家类型常量
    const PLAYER_TYPE_NORMAL = 1; // 普通玩家
    const PLAYER_TYPE_AGENT = 2; // 代理
    const PLAYER_TYPE_STORE_MACHINE = 3; // 店家

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];
    //简写省略id，默认后台用户表的id

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    protected $table = 'player';

    /**
     * 实体机钱包关系（platform_id = 1）
     *
     * @return HasOne
     */
    public function machine_wallet(): HasOne
    {
        return $this->hasOne(PlayerPlatformCash::class)
            ->where('platform_id', PlayerPlatformCash::PLATFORM_SELF);
    }

    /**
     * 玩家扩展信息
     * @return HasOne
     */
    public function player_extend(): HasOne
    {
        return $this->hasOne(PlayerExtend::class, 'player_id');
    }

    /**
     * 渠道信息
     * @return BelongsTo
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'department_id', 'department_id')->withTrashed();
    }

    /**
     * 提现信息
     * @return hasMany
     */
    public function player_withdraw_record(): hasMany
    {
        return $this->hasMany(PlayerWithdrawRecord::class, 'player_id');
    }

    /**
     * 提现信息
     * @return hasMany
     */
    public function player_recharge_record(): hasMany
    {
        return $this->hasMany(PlayerRechargeRecord::class, 'player_id');
    }

    /**
     * 转出
     * @return hasMany
     */
    public function present_out(): hasMany
    {
        return $this->hasMany(PlayerPresentRecord::class, 'user_id');
    }

    /**
     * 转入
     * @return hasMany
     */
    public function present_in(): hasMany
    {
        return $this->hasMany(PlayerPresentRecord::class, 'player_id');
    }

    /**
     * 电子游戏记录
     * @return hasMany
     */
    public function game_record(): hasMany
    {
        return $this->hasMany(PlayGameRecord::class, 'player_id');
    }

    /**
     * 玩家扩展信息
     * @return BelongsTo
     */
    public function recommend_player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'recommend_id');
    }

    public function the_last_player_login_record(): HasOne
    {
        return $this->hasOne(PlayerLoginRecord::class, 'player_id')->latest();
    }

    public function player_register_record(): HasOne
    {
        return $this->hasOne(PlayerRegisterRecord::class, 'player_id');
    }

    public function player_promoter(): HasOne
    {
        return $this->hasOne(PlayerPromoter::class, 'player_id');
    }

    /**
     * 密码哈希加密
     * @param $value
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * 支付密码哈希加密
     * @param $value
     */
    public function setPlayPasswordAttribute($value)
    {
        $this->attributes['play_password'] = password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * 获取器 - 标签id
     * @param $value
     * @return array
     */
    public function getPlayerTagAttribute($value)
    {
        // PHP 8.1+ 兼容：explode() 不接受 null
        if ($value === null || $value === '') {
            return [];
        }
        return array_filter(explode(',', $value));
    }

    /**
     * 修改器 - 标签id
     * @param $value
     * @return string
     */
    public function setPlayerTagAttribute($value): string
    {
        $idsStr = json_encode($value);
        $cacheKey = md5("player_tag_options_ids_$idsStr");
        Cache::delete($cacheKey);

        return $this->attributes['player_tag'] = implode(',', $value);
    }

    /**
     * 获取器 - 玩家头像
     * @param $value
     * @return false|string[]
     */
    public function getAvatarAttribute($value)
    {
        return is_numeric($value) ? config('def_avatar.' . $value) : $value;
    }

    /**
     * 所属推广员
     * @return BelongsTo
     */
    public function recommend_promoter(): BelongsTo
    {
        return $this->belongsTo(PlayerPromoter::class, 'recommend_id', 'player_id');
    }

    /**
     * 所属代理后台账号
     * @return BelongsTo
     */
    public function agentAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'agent_admin_id', 'id');
    }

    /**
     * 所属店家后台账号
     * @return BelongsTo
     */
    public function storeAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'store_admin_id', 'id');
    }

    /**
     * 玩家全民代理
     * @return HasOne
     */
    public function national_promoter(): HasOne
    {
        return $this->hasOne(NationalPromoter::class, 'uid');
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (Player $player) {
            $playerExtend = new PlayerExtend();
            $playerExtend->player_id = $player->id;
            $playerExtend->save();
        });
        static::updated(function (Player $player) {
            $columns = [
                'type',
                'is_coin',
                'is_promoter',
                'status',
                'status_withdraw',
                'status_open_point',
                'machine_play_num',
                'name',
                'phone',
                'country_code',
                'play_password',
                'password',
                'flag',
                'avatar',
                'player_tag',
                'remark',
                'status_reverse_water',
                'status_national',
                'status_machine',
                'status_offline_open',
                'status_baccarat',
                'status_game_platform',
                'is_test',
                'recommend_id'
            ];
            if ($player->wasChanged($columns) && false) {
                $orData = $player->getOriginal();
                $changeData = $player->getChanges();
                $orDataArr = [];
                $newDataArr = [];
                foreach ($changeData as $key => $item) {
                    if (empty($item) == empty($orData[$key])) {
                        continue;
                    }
                    if ($key == 'updated_at') {
                        $orData[$key] = date('Y-m-d H:i:s', strtotime($orData[$key]));
                    }
                    $orDataArr[$key] = $orData[$key];
                    $newDataArr[$key] = $item;
                }
                if (!empty($newDataArr)) {
                    $playerEditLog = new PlayerEditLog();
                    $playerEditLog->player_id = $player->id;
                    $playerEditLog->department_id = $player->department_id;
                    $playerEditLog->origin_data = json_encode($orDataArr);
                    $playerEditLog->new_data = json_encode($newDataArr);
                    $playerEditLog->user_id = 0;
                    $playerEditLog->user_name = 'system';
                    $playerEditLog->save();
                }
            }
        });
    }

    /**
     * 玩家银行卡
     * @return hasMany
     */
    public function bankCard(): hasMany
    {
        return $this->hasMany(PlayerBank::class, 'player_id');
    }
}
