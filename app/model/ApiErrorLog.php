<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class ApiErrorLog
 * @property int id 主键
 * @property int player_id 玩家id
 * @property int target
 * @property int target_id
 * @property string url 地址
 * @property string params 参数
 * @property string content 内容
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class ApiErrorLog extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.api_error_log_table'));
    }
}
