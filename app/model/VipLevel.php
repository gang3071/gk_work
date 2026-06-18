<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Class VipLevel
 * @property int id 主键
 * @property string name 等级名称
 * @property int upgrade_limit_days 升级限制时间（天数）
 * @property int retain_level_days 保级时间（天数）
 * @property float retain_level_bet_amount 保级所需打码量
 * @property float upgrade_bet_amount 升级所需打码量
 * @property float min_claim_amount 最小领取额
 * @property float birthday_bonus 生日礼金
 * @property int sort 排序
 * @property int status 状态（0=禁用，1=启用）
 * @property int department_id 部门/渠道ID
 * @property string created_at 创建时间
 * @property string updated_at 更新时间
 * @package app\model
 */
class VipLevel extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'vip_level';

    /**
     * 状态常量
     */
    const STATUS_DISABLED = 0; // 禁用
    const STATUS_ENABLED = 1;  // 启用

    protected $guarded = [];

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
