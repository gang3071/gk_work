<?php

namespace app\model;

use app\traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Class LotteryPool
 * 彩金池模型
 * @package app\model
 */
class LotteryPool extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'lottery_pool';
}
