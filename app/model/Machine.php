<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use support\Cache;

/**
 * Class Machine
 * @property int id 主键
 * @property int cate_id 機台類別id
 * @property int producer_id 厂商id
 * @property int label_id 标签id
 * @property string code 機台編號
 * @property string name 機台名称
 * @property string picture_url 图片
 * @property int type 机台类型
 * @property string domain domain
 * @property string ip 機台ip 機台控制IP
 * @property string port 機台port
 * @property string auto_card_port 开分卡port
 * @property string auto_card_domain 开分卡IP
 * @property string identify_url 鱼机图像识别地址
 * @property int seat 炮台位置
 * @property string currency 機台分數幣值
 * @property int open_point 開分
 * @property int wash_point 洗分
 * @property int now_turn_point 目前轉數
 * @property int seven_turn_point 七段轉數
 * @property int seven_bead_point 七段珠數
 * @property int seven_open_point 七段開分
 * @property int player_turn_point 客人進入時原始轉數
 * @property int player_seven_turn_point 客人進入時原始七段轉數
 * @property int pressure 壓分
 * @property int score 得分
 * @property int player_pressure 客人進入時原始壓分
 * @property int player_score 客人進入時原始得分
 * @property float odds_x 比值X
 * @property float odds_y 比值Y
 * @property int min_point 最低上分金額
 * @property int max_point 最高上分金額
 * @property int control_open_point 工控壓分
 * @property int is_opening 是否開獎中
 * @property int bonus_accumulate 累積達標彩金
 * @property int auto_up_turn 啟動自動上轉
 * @property int push_auto 是否push_auto
 * @property int amount 機台運轉次數，用來確認玩家是否還有再玩
 * @property int keep_seconds 累計可保留時間(秒數)
 * @property int wash_limit 下分限制
 * @property string remark 备注
 * @property int status 状态
 * @property int sort 排序
 * @property int move 移分 0=OFF 1=ON
 * @property int is_bonus 是否正在拉彩
 * @property int is_open 是否正在上分，防止小人一直洗
 * @property int is_live 現場機台 0=否 1=是
 * @property int gaming 遊戲中，0=否, 1=是
 * @property int keeping 保留 0=否 1=是
 * @property int maintaining 維護 0=否 1=是
 * @property int gaming_user_id 遊戲中玩家
 * @property int keeping_user_id 保留中玩家
 * @property int strategy_id 攻略id
 * @property int is_use 使用中
 * @property int control_type 工控类型
 * @property int correct_rate 确率
 * @property int is_special 是否特仕机
 * @property string last_game_at 最後遊戲時間
 * @property string last_point_at 最後上下分時間
 * @property string last_keep_at 最後保留時間
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property MachineCategory machineCategory
 * @property Player gamingPlayer
 * @property Player keepingPlayer
 * @property MachineMedia machine_media
 * @property MachineStrategy machine_strategy
 * @property MachineProducer producer
 * @property MachineLabel machineLabel
 * @package app\model
 */
class Machine extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    const CONTROL_TYPE_MEI = 1;
    const CONTROL_TYPE_SONG = 2;

    protected $name;
    protected $correct_rate;
    protected $picture_url;
    protected $table = 'machine';

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::updated(function (Machine $machine) {
            $columns = [
                'cate_id',
                'name',
                'code',
                'picture_url',
                'type',
                'domain',
                'ip',
                'port',
                'currency',
                'odds_x',
                'odds_y',
                'min_point',
                'max_point',
                'control_open_point',
                'auto_up_turn',
                'push_auto',
                'keep_seconds',
                'wash_limit',
                'remark',
                'correct_rate',
                'status',
                'sort',
                'strategy_id',
                'gaming_user_id',
                'gaming',
                'is_use',
                'maintaining',
            ];
            if ($machine->wasChanged($columns)) {
                $orData = $machine->getOriginal();
                $changeData = $machine->getChanges();
                if (false) { // API项目中无管理员操作
                    $orDataArr = [];
                    foreach ($changeData as $key => $item) {
                        if ($key == 'updated_at') {
                            $orData[$key] = date('Y-m-d H:i:s', strtotime($orData[$key]));
                        }
                        $orDataArr[$key] = $orData[$key];
                    }
                    $machineEditLog = new MachineEditLog();
                    $machineEditLog->machine_id = $machine->id;
                    $machineEditLog->department_id = 0;
                    $machineEditLog->source = MachineEditLog::SOURCE_MACHINE;
                    $machineEditLog->origin_data = json_encode($orDataArr);
                    $machineEditLog->new_data = json_encode($changeData);
                    $machineEditLog->user_id = 0;
                    $machineEditLog->user_name = 'system';
                    $machineEditLog->save();
                }
                switch ($machine->type) {
                    case GameType::TYPE_SLOT:
                        Cache::delete(sprintf('machine:domain:%s:port:%s:type:%s',
                            $orData['domain'], $orData['port'], $orData['type']
                        ));
                        Cache::delete(sprintf('machine:domain:%s:port:%s:type:%s',
                            $orData['auto_card_domain'], $orData['auto_card_port'], $orData['type']
                        ));
                        break;
                    case GameType::TYPE_STEEL_BALL:
                        Cache::delete(sprintf('machine:domain:%s:port:%s:type:%s',
                            $orData['domain'], $orData['port'], $orData['type']
                        ));
                        break;
                }
            }
        });
    }

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function machineCategory(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_category_model'), 'cate_id');
    }

    /**
     * 正在游戏玩家
     * @return HasOne
     */
    public function gamingPlayer(): HasOne
    {
        return $this->hasOne(plugin()->webman->config('database.player_model'), 'id', 'gaming_user_id')->withTrashed();
    }

    /**
     * 机器标签
     * @return belongsTo
     */
    public function machineLabel(): belongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_label_model'), 'label_id');
    }

    /**
     * 保留玩家
     * @return HasOne
     */
    public function keepingPlayer(): HasOne
    {
        return $this->hasOne(plugin()->webman->config('database.player_model'), 'id', 'keeping_user_id')->withTrashed();
    }

    /**
     * 获取name属性
     * @return string
     */
    public function getNameAttribute()
    {
        return $this->machineLabel->name ?? '';
    }

    /**
     * 获取correct_rate属性
     * @return string
     */
    public function getCorrectRateAttribute()
    {
        return $this->machineLabel->correct_rate ?? 0;
    }

    /**
     * 获取picture_url属性
     * @return string
     */
    public function getPictureUrlAttribute()
    {
        return $this->machineLabel->picture_url ?? '';
    }

    /**
     * 媒体服务
     * @return HasMany
     */
    public function machine_media(): HasMany
    {
        return $this->hasMany(plugin()->webman->config('database.machine_media_model'), 'machine_id');
    }

    /**
     * 机台攻略
     * @return hasOne
     */
    public function machine_strategy(): hasOne
    {
        return $this->hasOne(plugin()->webman->config('database.machine_strategy_model'), 'strategy_id');
    }

    /**
     * 厂商
     * @return BelongsTo
     */
    public function producer(): BelongsTo
    {
        return $this->belongsTo(plugin()->webman->config('database.machine_producer_model'),
            'producer_id')->withTrashed();
    }
}
