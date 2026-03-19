<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use support\Cache;

/**
 * Class Player
 * @property int id 主键
 * @property int name 标签名称
 * @property string created_at 创建时间
 * @property string updated_at 最后一次修改时间
 *
 * @package app\model
 */
class PlayerTag extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'player_tag';

    /**
     * 时间转换
     * @param DateTimeInterface $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * 模型的 "booted" 方法
     *
     * @return void
     */
    protected static function booted()
    {
        static::created(function () {
            $cacheKey = "doc_player_tag_options_filter";
            $data = (new PlayerTag())->select(['name', 'id'])->get()->toArray();
            $data = $data ? array_column($data, 'name', 'id') : [];
            Cache::set($cacheKey, $data, 24 * 60 * 60);
        });
        static::deleted(function () {
            $cacheKey = "doc_player_tag_options_filter";
            $data = (new PlayerTag())->select(['name', 'id'])->get()->toArray();
            $data = $data ? array_column($data, 'name', 'id') : [];
            Cache::set($cacheKey, $data, 24 * 60 * 60);
        });
        static::updated(function () {
            $cacheKey = "doc_player_tag_options_filter";
            $data = (new PlayerTag())->select(['name', 'id'])->get()->toArray();
            $data = $data ? array_column($data, 'name', 'id') : [];
            Cache::set($cacheKey, $data, 24 * 60 * 60);
        });
    }
}
