<?php

/**
 * 游戏平台过滤配置
 *
 * 用于统一管理哪些平台参与/不参与各种统计功能
 *
 * 修改历史：
 * - 2026-07-13: 创建配置文件，统一管理平台过滤规则
 */

return [
    /**
     * 真人视讯和体育平台代码列表
     *
     * 这些平台的特点：
     * 1. 不参与彩金系统（不累加彩金池、不触发中奖）
     * 2. 不参与摸奖券系统（不计入打码量、不发放摸奖券）
     * 3. 不发送高分广播消息
     * 4. 不参与VIP等级升级打码量统计（根据业务需求）
     *
     * 平台分类：
     * - 真人视讯：WM、DG、SA、RSGLIVE、MT、O8、TNINE
     * - 混合平台：KY（包含真人类别）
     * - 体育平台：KYS、OB、SPS、SPS_DY
     */
    'excluded_platforms' => [
        'WM',       // WM真人
        'DG',       // DG真人
        'SA',       // SA真人
        'RSGLIVE',  // GClub真人
        'MT',       // MT真人
        'O8',       // EEAI真人
        'TNINE',    // TNINE真人
        'KY',       // KY棋牌（混合平台，包含真人类别）
        'KYS',      // KYSport
        'OB',       // OB
        'SPS',      // SPSport
        'SPS_DY',   // SPSport单一钱包
    ],

    /**
     * 电子游戏平台代码列表
     *
     * 这些平台正常参与所有统计功能：
     * - 彩金系统
     * - 摸奖券系统
     * - VIP等级升级
     * - 高分广播
     */
    'included_platforms' => [
        'BTG',         // BTG
        'RSG',         // RSG
        'ATG',         // ATG
        'JDB',         // JDB
        'YZG',         // YZG
        'SP',          // SP
        'KT',          // KT
        'TNINE_SLOT',  // T9电子游戏
        'STM',         // SlotMill
        'HS',          // Hacksaw
        'QT',          // QT
    ],

    /**
     * 获取排除的平台代码数组
     *
     * @return array
     */
    'get_excluded_codes' => function () {
        return config('platform_filter.excluded_platforms', []);
    },

    /**
     * 获取包含的平台代码数组
     *
     * @return array
     */
    'get_included_codes' => function () {
        return config('platform_filter.included_platforms', []);
    },

    /**
     * 检查平台代码是否被排除
     *
     * @param string $code 平台代码
     * @return bool
     */
    'is_excluded' => function (string $code): bool {
        $excluded = config('platform_filter.excluded_platforms', []);
        return in_array($code, $excluded, true);
    },

    /**
     * 检查平台代码是否被包含
     *
     * @param string $code 平台代码
     * @return bool
     */
    'is_included' => function (string $code): bool {
        $included = config('platform_filter.included_platforms', []);
        return in_array($code, $included, true);
    },
];
