<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Class IpWhitelist
 * @property int id 主键
 * @property int ip_address ip地址
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class IpWhitelist extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'ip_white_list';
}
