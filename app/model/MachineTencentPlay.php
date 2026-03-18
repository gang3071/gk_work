<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Class MachineTencentPlay
 * @property int id 主键
 * @property string title 线路标题
 * @property string push_domain 推流域名
 * @property string push_key 推流key
 * @property string pull_domain 拉流域名
 * @property string pull_key 拉流key
 * @property string pull_domain_cn 拉流域名大陆
 * @property string pull_key_cn 拉流key大陆
 * @property string license 播放器URL
 * @property string license_key 播放器KEY
 * @property string status 状态 0 为开启 1开启
 * @property string api_appid 腾讯云APPID
 * @property string api_key 腾讯云API KEY
 * @property string play_domain 腾讯云域名
 * @property string created_at 更新时间
 * @property string updated_at 创建时间
 *
 * @package app\model
 */
class MachineTencentPlay extends Model
{
    use HasDateTimeFormatter;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.machine_tencent_play_table'));
    }
}
