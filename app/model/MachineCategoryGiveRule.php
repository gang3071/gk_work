<?php

namespace app\model;


use Illuminate\Database\Eloquent\Model;

/**
 * Class MachineCategoryGiveRule
 * @property int id 主键
 * @property int machine_category_id 机台分类表id
 * @property int open_num 开分点数
 * @property int give_num 赠送分数
 * @property double condition 满足完成条件
 * @property int status 是否生效   0：失效 1：生效
 * @property int give_rule_num 开分赠点每日次数限制
 *
 * @package app\model
 */
class MachineCategoryGiveRule extends Model
{

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(plugin()->webman->config('database.machine_category_give_rule_table'));
    }
}