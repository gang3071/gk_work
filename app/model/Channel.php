<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Cache;

/**
 * Class Channel
 * @property int id 主键
 * @property string name 渠道名称
 * @property string sms_name 短信名称
 * @property string domain 渠道域名
 * @property string domain_ext 扩展渠道域名
 * @property string download_url 下载地址
 * @property string lang 语言
 * @property string currency 币别代码
 * @property int department_id 所属部门
 * @property int user_id 管理员id
 * @property int site_id 渠道编号
 * @property string site_name 站点名称
 * @property int status 状态(0:禁用,1:启用)
 * @property int recharge_status 平台充值(0:禁用,1:启用)
 * @property int q_talk_recharge_status Q币充值(0:禁用,1:启用)
 * @property int q_talk_point_status Q币转入(0:禁用,1:启用)
 * @property int withdraw_status 人工提现(0:禁用,1:启用)
 * @property int q_talk_withdraw_status Q币转出(0:禁用,1:启用)
 * @property int web_login_status web登录状态(0:禁用,1:启用)
 * @property int promotion_status 推广员功能(0:禁用,1:启用)
 * @property int wallet_action_status 钱包操作功能(0:禁用,1:启用)
 * @property int coin_status 币商功能(0:禁用,1:启用)
 * @property int line_login_status line登录功能(0:禁用,1:启用)
 * @property int national_promoter_status 全民代理功能(0:禁用,1:启用)
 * @property int reverse_water_status 电子游戏反水(0:禁用,1:启用)
 * @property int gb_payment_recharge_status 购宝充值(0:禁用,1:启用)
 * @property int gb_payment_withdraw_status 购宝提现(0:禁用,1:启用)
 * @property int eh_payment_recharge_status EH充值(0:禁用,1:启用)
 * @property int eh_payment_withdraw_status EH提现(0:禁用,1:启用)
 * @property int ranking_status 排行榜功能(0:禁用,1:启用)
 * @property int discussion_group_status 讨论群功能(0:禁用,1:启用)
 * @property int activity_status 活动状态(0:禁用,1:启用)
 * @property int lottery_status 彩金状态(0:禁用,1:启用)
 * @property int status_machine 实体机台开关 0关闭1-开启
 * @property int line_client_id line client id
 * @property float recharge_amount 官方总充值点数
 * @property float withdraw_amount 官方总提现点数
 * @property float present_in_amount 币商转入点数
 * @property float present_out_amount 币商转出点数
 * @property float third_recharge_amount 第三方总充值点数
 * @property float third_withdraw_amount 第三方总提现点数
 * @property string game_platform 电子游戏
 * @property string deleted_at 删除时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property int machine_num 机器数量
 * @property int machine_media_line 机台线路
 * @property string client_version 客户端版本
 * @property int app_version_code APP版本号(数字)
 * @property string app_update_title 更新标题
 * @property string app_update_content 更新内容说明
 * @property int app_force_update 是否强制更新(0:否,1:是)
 * @property string app_download_url APK下载地址
 * @property string app_file_size APK文件大小
 * @property string app_file_md5 APK文件MD5校验值
 * @property int is_offline 是否线下站(0:否,1:是)
 * @property float total_profit_amount 总分润
 * @property float profit_amount 当期分润
 * @property float ratio 分润比例
 * @property float adjust_amount 分润调整金额
 * @property float last_profit_amount 上次结算分润
 * @property string last_settlement_timestamp 上次结算时间
 * @property string last_settlement_time 上次结算日期
 * @property string settlement_amount 已结算金额
 * @property int type 类型 1直营 2外接API 3代理
 *
 * @property AdminDepartment department
 * @property AdminUser user
 * @property Player player
 * @property ExternalApp externalApp
 * @property PlayerPlatformCash wallet
 * @package app\model
 */
class Channel extends Model
{
    use HasDateTimeFormatter, SoftDeletes;

    const TYPE_STORE = 1; // 直营
    const TYPE_API = 2; // api
    const TYPE_AGENT = 3; // 代理

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(plugin()->webman->config('database.channel_table'));
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (Channel $channel) {
            $cacheKey = "channel_" . $channel->site_id;
            Cache::set($cacheKey, $channel->toArray());
            // 创建渠道系统配置
            SystemSetting::insert([
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'marquee',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'machine_marquee',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'line_customer',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'line_discussion_group',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'line_redirect_uri',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'line_key',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'line_secret',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'department_id' => $channel->department_id,
                    'feature' => 'commission',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ]);
            ChannelRechargeMethod::insert([
                [
                    'name' => 'USDT',
                    'department_id' => $channel->department_id,
                    'type' => ChannelRechargeMethod::TYPE_USDT,
                ],
                [
                    'name' => '支付宝',
                    'department_id' => $channel->department_id,
                    'type' => ChannelRechargeMethod::TYPE_ALI,
                ],
                [
                    'name' => '微信',
                    'department_id' => $channel->department_id,
                    'type' => ChannelRechargeMethod::TYPE_WECHAT,
                ],
                [
                    'name' => '银行卡',
                    'department_id' => $channel->department_id,
                    'type' => ChannelRechargeMethod::TYPE_BANK,
                ],
                [
                    'name' => '购宝钱包',
                    'department_id' => $channel->department_id,
                    'type' => ChannelRechargeMethod::TYPE_GB,
                ],
            ]);
        });
        static::deleted(function (Channel $channel) {
            $cacheKey = "channel_" . $channel->site_id;
            Cache::delete($cacheKey);
        });
        static::updated(function (Channel $channel) {
            $cacheKey = "channel_" . $channel->site_id;
            Cache::set($cacheKey, $channel->toArray());
        });
    }

    /**
     * 部门
     * @return BelongsTo
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.department_model'), 'department_id')->withTrashed();
    }

    /**
     * 管理员用户
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.user_model'), 'user_id')->withTrashed();
    }

    /**
     * 对外应用
     * @return hasOne
     */
    public function externalApp(): hasOne
    {
        return $this->hasOne(plugin()->webman->config('database.external_app_model'), 'department_id', 'department_id');
    }

    /**
     * 管理员用户
     * @return hasMany
     */
    public function player(): hasMany
    {
        return $this->hasMany(plugin()->webman->config('database.player_model'), 'department_id', 'department_id');
    }

    /**
     * 管理员用户
     * @return hasManyThrough
     */
    public function wallet(): hasManyThrough
    {
        return $this->hasManyThrough(plugin()->webman->config('database.player_platform_cash_model'),
            plugin()->webman->config('database.player_model'), 'department_id', 'player_id', 'department_id', 'id');
    }

    public function getDomainExtAttribute($value)
    {
        if (is_null($value)) return null;
        return json_decode($value, true);
    }
}
