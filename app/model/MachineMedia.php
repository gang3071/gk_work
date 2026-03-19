<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class MachineMedia
 * @property int id 主键
 * @property int machine_id 机台id
 * @property string push_ip 推流IP
 * @property string pull_ip 拉流IP
 * @property string media_ip 機台影像ip
 * @property string media_app 媒体服务APP
 * @property string stream_name 串流名稱 機台影像串流名稱
 * @property int status 状态
 * @property int is_ams AMS回源线路
 * @property int sort 排序
 * @property int user_id 管理员id
 * @property string user_name 管理员名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 * @property string deleted_at 删除时间
 *
 * @property Machine machine
 * @property MachineMediaPush machineMediaPush
 * @package app\model
 */
class MachineMedia extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    protected $table = 'machine_media';

    /**
     * 游戏类别
     * @return BelongsTo
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id')->withTrashed();
    }

    /**
     * 游戏类别
     * @return hasMany
     */
    public function machineMediaPush(): hasMany
    {
        return $this->hasMany(MachineMediaPush::class, 'media_id');
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::updated(function (MachineMedia $machineMedia) {
            $columns = [
                'push_ip',
                'pull_ip',
                'media_ip',
                'stream_name',
                'media_app',
                'status',
                'deleted_at',
            ];
            if ($machineMedia->wasChanged($columns) && false) {
                $orData = $machineMedia->getOriginal();
                $changeData = $machineMedia->getChanges();
                $orDataArr = [];
                foreach ($changeData as $key => $item) {
                    if ($key == 'updated_at' || $key == 'deleted_at') {
                        $orData[$key] = date('Y-m-d H:i:s', strtotime($orData[$key]));
                    }
                    $orDataArr[$key] = $orData[$key];
                }
                $machineEditLog = new MachineEditLog();
                $machineEditLog->machine_id = $machineMedia->machine_id;
                $machineEditLog->department_id = 0;
                $machineEditLog->source = MachineEditLog::SOURCE_MEDIA;
                $machineEditLog->origin_data = json_encode($orDataArr);
                $machineEditLog->new_data = json_encode($changeData);
                $machineEditLog->user_id = 0;
                $machineEditLog->user_name = 'system';
                $machineEditLog->save();
            }
        });
    }
}
