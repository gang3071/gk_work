<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class ChannelRechargeSetting
 * @property int id 主键
 * @property string name 姓名
 * @property int department_id 所属部门/渠道
 * @property int method_id 充值方式
 * @property string bank_name 银行
 * @property string sub_bank 支行
 * @property string wallet_address 钱包地址
 * @property string qr_code 二维码
 * @property string account 银行账户
 * @property string currency 货币
 * @property string gb_token 购宝Token
 * @property string gb_secret 购宝请求密钥
 * @property int user_id 管理员id
 * @property int user_name 管理员名称
 * @property int status 状态(0:禁用,1:启用)
 * @property float max 最大金额
 * @property float min 最小金额
 * @property int type 类型
 * @property string deleted_at 删除时间
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @property AdminDepartment department
 * @property AdminUser user
 * @property ChannelRechargeMethod channel_recharge_method
 * @package app\model
 */
class ChannelRechargeSetting extends Model
{
    use HasDateTimeFormatter, SoftDeletes;

    //数据权限字段
    protected $dataAuth = ['department_id' => 'department_id'];
    protected $table = 'channel_recharge_setting';

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
     * 充值方式
     * @return BelongsTo
     */
    public function channel_recharge_method(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.channel_recharge_method_model'), 'method_id')->withTrashed();
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function (ChannelRechargeSetting $channelRechargeSetting) {
            saveChannelFinancialRecord($channelRechargeSetting, ChannelFinancialRecord::ACTION_RECHARGE_SETTING_ADD);
        });
        static::saved(function (ChannelRechargeSetting $channelRechargeSetting) {
            $changeData = $channelRechargeSetting->getChanges();
            $originalData = $channelRechargeSetting->getOriginal();
            $hasChange = false;
            foreach ($changeData as $k => $v) {
                if (!in_array($k, ['status', 'updated_at']) && $v != $originalData[$k]) {
                    $hasChange = true;
                }
            }
            if ($hasChange) {
                saveChannelFinancialRecord($channelRechargeSetting, ChannelFinancialRecord::ACTION_RECHARGE_SETTING_EDIT);
            }
            if (array_key_exists('status', $changeData) && $changeData['status'] != $originalData['status']) {
                if ($channelRechargeSetting->status == 1) {
                    saveChannelFinancialRecord($channelRechargeSetting, ChannelFinancialRecord::ACTION_RECHARGE_SETTING_ENABLE);
                }
                if ($channelRechargeSetting->status == 0) {
                    saveChannelFinancialRecord($channelRechargeSetting, ChannelFinancialRecord::ACTION_RECHARGE_SETTING_STOP);
                }
            }
        });
    }
}
