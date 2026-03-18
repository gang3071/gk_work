<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class GamePlatform
 * @property int id 主键
 * @property string $code 游戏平台code
 * @property string name 平台名称
 * @property string config 配置
 * @property float ratio 电子游戏平台比值
 * @property string logo logo
 * @property string picture picture
 * @property string cate_id 游戏类型
 * @property int display_mode 展示模式
 * @property int status 状态
 * @property int has_lobby 是否进入大厅
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @package app\model
 */
class GamePlatform extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    // 展示模式常量
    const DISPLAY_MODE_LANDSCAPE = 1; // 横版
    const DISPLAY_MODE_PORTRAIT = 2;  // 竖版
    const DISPLAY_MODE_ALL = 3;       // 全部支持

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.game_platform_table'));
    }
}
