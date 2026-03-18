<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Cache;

/**
 * 外部应用
 * Class ExternalApp
 * @property int id 主键
 * @property string app_name 应用名
 * @property string white_ip 白化IP
 * @property string app_id app_id
 * @property string app_secret app_secret
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property int status 状态
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 * @property string expired_at 过期时间
 * @property string encrypt_body 状态：0=禁用，1=启用 算法：aes-128-cbc 是否加密body传入加密后的报文字符串，启用RSA需要使用自动生成的app_secret进行对称加密，否则使用固定的app_secret进行对称加密
 * @property string rsa_status 状态：0=禁用，1=启用 启用RSA，主要用rsa加密随机生成的app_secret，而不使用固定app_secret
 * @property string private_key sign私钥
 * @property string public_key sign公钥
 * @property string department_id 部门id
 * @property string notify_url 回调地址
 *
 * @property Channel channel 渠道
 * @package app\model
 */
class ExternalApp extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.external_app_table'));
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::deleted(function (ExternalApp $externalApp) {
            $cacheKey = "agent_" . $externalApp->app_id;
            Cache::delete($cacheKey);
        });
        static::updated(function (ExternalApp $externalApp) {
            $cacheKey = "agent_" . $externalApp->app_id;
            Cache::set($cacheKey, $externalApp);
        });
    }

    /**
     * 渠道信息
     * @return hasOne
     */
    public function channel(): hasOne
    {
        return $this->hasOne(plugin()->webman->config('database.channel_model'), 'department_id',
            'department_id')->withTrashed();
    }
}
