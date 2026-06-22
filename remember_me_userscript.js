// ==UserScript==
// @name         后台"记住我"功能修复
// @namespace    http://tampermonkey.net/
// @version      1.0
// @description  修复后台"记住我"功能，支持15天免登录
// @author       You
// @match        https://zhu-test.5super9.com/*
// @match        https://zi-test.5super9.com/*
// @match        https://agent-test.5super9.com/*
// @match        https://store-test.5super9.com/*
// @grant        none
// @run-at       document-end
// ==/UserScript==

(function() {
    'use strict';

    console.log('[RememberMe] 用户脚本已加载');

    // 拦截登录请求，在响应后添加记住我标记
    const originalFetch = window.fetch;
    window.fetch = function(...args) {
        return originalFetch.apply(this, args).then(response => {
            // 克隆响应以便我们可以读取它
            const clonedResponse = response.clone();

            // 检查是否是登录接口
            if (args[0] && args[0].includes('/ex-admin/login/check')) {
                clonedResponse.json().then(data => {
                    console.log('[RememberMe] 检测到登录请求');

                    if (data.code === 0 && data.data && data.data.remember_me) {
                        console.log('[RememberMe] 检测到"记住我"勾选，保存标记');

                        // 根据当前域名判断source
                        const hostname = window.location.hostname;
                        let source = 'admin';
                        if (hostname.includes('zi-test')) source = 'channel';
                        else if (hostname.includes('agent-test')) source = 'agent';
                        else if (hostname.includes('store-test')) source = 'store';

                        // 保存记住我标记
                        const tokenExpireTime = Date.now() + (15 * 24 * 60 * 60 * 1000); // 15天后
                        const tokenExpireKey = `ex_admin_token_expire_${source}`;
                        const rememberMeKey = `ex_admin_remember_me_${source}`;

                        localStorage.setItem(tokenExpireKey, tokenExpireTime.toString());
                        localStorage.setItem(rememberMeKey, 'true');

                        console.log(`[RememberMe] 已保存标记到 localStorage:`);
                        console.log(`  ${tokenExpireKey}: ${new Date(tokenExpireTime).toLocaleString()}`);
                        console.log(`  ${rememberMeKey}: true`);
                    } else if (data.code === 0) {
                        console.log('[RememberMe] 未勾选"记住我"，清除标记');

                        const hostname = window.location.hostname;
                        let source = 'admin';
                        if (hostname.includes('zi-test')) source = 'channel';
                        else if (hostname.includes('agent-test')) source = 'agent';
                        else if (hostname.includes('store-test')) source = 'store';

                        localStorage.removeItem(`ex_admin_token_expire_${source}`);
                        localStorage.removeItem(`ex_admin_remember_me_${source}`);
                    }
                }).catch(err => {
                    console.error('[RememberMe] 解析响应失败:', err);
                });
            }

            return response;
        });
    };

    // 页面加载时检查记住我状态
    window.addEventListener('load', function() {
        const hostname = window.location.hostname;
        let source = 'admin';
        if (hostname.includes('zi-test')) source = 'channel';
        else if (hostname.includes('agent-test')) source = 'agent';
        else if (hostname.includes('store-test')) source = 'store';

        const tokenExpireKey = `ex_admin_token_expire_${source}`;
        const rememberMeKey = `ex_admin_remember_me_${source}`;
        const tokenKey = `/${source}_ex-admin-token`;

        const hasToken = localStorage.getItem(tokenKey);
        const rememberMe = localStorage.getItem(rememberMeKey);
        const expireTime = localStorage.getItem(tokenExpireKey);

        console.log('[RememberMe] 当前状态:');
        console.log(`  Token存在: ${!!hasToken}`);
        console.log(`  记住我标记: ${rememberMe}`);
        console.log(`  过期时间: ${expireTime ? new Date(parseInt(expireTime)).toLocaleString() : '未设置'}`);

        if (hasToken && rememberMe === 'true' && expireTime) {
            const now = Date.now();
            const expire = parseInt(expireTime);
            if (expire > now) {
                console.log(`[RememberMe] ✅ Token有效期至 ${new Date(expire).toLocaleString()}`);
            } else {
                console.log('[RememberMe] ❌ Token已过期，清除标记');
                localStorage.removeItem(tokenExpireKey);
                localStorage.removeItem(rememberMeKey);
                localStorage.removeItem(tokenKey);
            }
        }
    });
})();
