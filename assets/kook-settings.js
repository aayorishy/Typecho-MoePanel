(function () {
    'use strict';

    var cfg = window.MoePanelKookSettings || window.KookAuthSettings;

    if (!cfg || !cfg.endpoint || !cfg.token) {
        return;
    }

    var form = document.querySelector('form[action*="plugins-edit"]');

    if (!form) {
        return;
    }

    var fields = Array.isArray(cfg.fields) ? cfg.fields : [];
    var timer = null;
    var saving = false;
    var pending = false;
    var dirty = false;
    var lastSaved = '';

    var pill = document.createElement('div');
    pill.className = 'kookauth-save';
    pill.setAttribute('data-state', 'idle');
    pill.innerHTML = '<span class="kookauth-save-dot" aria-hidden="true"></span>'
        + '<span class="kookauth-save-text" role="status" aria-live="polite">自动保存</span>';
    document.body.appendChild(pill);

    var text = pill.querySelector('.kookauth-save-text');
    var pendingManual = false;

    var toast = document.createElement('div');
    toast.className = 'sp-ks-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.hidden = true;
    document.body.appendChild(toast);
    var toastTimer = null;

    function showToast(message, type, detail) {
        window.clearTimeout(toastTimer);
        toast.innerHTML = '<span class="sp-ks-toast-icon">' + ('error' === type ? '!' : '✓') + '</span>'
            + '<span><strong>' + message + '</strong>' + (detail ? '<small>' + detail + '</small>' : '') + '</span>';
        toast.setAttribute('data-type', type || 'success');
        toast.hidden = false;
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
            window.setTimeout(function () { toast.hidden = true; }, 180);
        }, 2600);
    }

    var hint = document.createElement('p');
    hint.className = 'kookauth-autosave-hint';
    hint.textContent = 'KOOK 与品牌设置修改后会自动保存，无需手动提交。';
    form.parentNode.insertBefore(hint, form);

    function field(name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function radioValue(name) {
        var checked = form.querySelector('[name="' + name + '"]:checked');
        return checked ? checked.value : '';
    }

    function setState(state, message) {
        pill.setAttribute('data-state', state);
        text.textContent = message;
    }

    function collect() {
        var data = {};

        fields.forEach(function (name) {
            var nodes = form.querySelectorAll('[name="' + name + '"]');

            if (!nodes.length) {
                return;
            }

            var first = nodes[0];

            if ('radio' === first.type) {
                data[name] = radioValue(name);
            } else if ('checkbox' === first.type) {
                data[name] = first.checked ? '1' : '0';
            } else {
                data[name] = first.value;
            }
        });

        return data;
    }

    function requestSave(isManual) {
        if (saving) {
            pending = true;
            pendingManual = pendingManual || !!isManual;
            return;
        }

        saving = true;
        pending = false;
        var manualSave = !!isManual || pendingManual;
        pendingManual = false;
        setState('saving', '保存中…');

        fetch(cfg.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                _: cfg.token,
                settings: collect()
            })
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (payload && payload.ok) {
                dirty = false;
                lastSaved = payload.savedAt || '';
                setState('saved', '已保存' + (lastSaved ? ' · ' + lastSaved : ''));
                if (manualSave) {
                    showToast('设置已保存', 'success', lastSaved ? '保存于 ' + lastSaved : '所有更改已写入');
                }
            } else {
                var message = (payload && payload.error) || '请重试';
                setState('error', '保存失败');
                if (manualSave) {
                    showToast('保存失败', 'error', message);
                }
            }
        }).catch(function () {
            setState('error', '保存失败');
            if (manualSave) {
                showToast('保存失败', 'error', '请检查网络后重试');
            }
        }).then(function () {
            saving = false;

            if (pending) {
                var nextManual = pendingManual;
                pending = false;
                pendingManual = false;
                requestSave(nextManual);
            }
        });
    }

    function schedule() {
        dirty = true;
        setState('dirty', '未保存');

        window.clearTimeout(timer);
        timer = window.setTimeout(requestSave, 700);
    }

    // ------------------------------------------------------------------
    // Visual settings layout: tabs, status chips, card / logo previews.
    // ------------------------------------------------------------------

    var sectionMap = [
        {
            id: 'login',
            group: 'KOOK',
            title: '登录方式',
            hint: 'KOOK 登录总开关、OAuth、验证码与密码回退。',
            fields: ['enable', 'oauthLogin', 'codeLogin', 'passwordFallback', 'successCard', 'syncDisplayName']
        },
        {
            id: 'card',
            group: '网站',
            hidden: true,
            title: '卡片消息',
            fields: ['cardColor', 'verifyCardTitle', 'verifyCardContent', 'verifyCardContext', 'successCardTitle', 'successCardContent', 'successCardContext']
        },
        {
            id: 'app',
            group: 'KOOK',
            title: '应用设置',
            hint: '机器人凭据、账号映射、验证码策略、卡片、欢迎、身份组与 GitHub。',
            fields: ['clientId', 'clientSecret', 'botToken', 'guildId', 'uploadChannelId', 'ownerKookId', 'ownerUid', 'codeTtl', 'resendInterval', 'maxAttempts', 'githubApiToken']
        },
        {
            id: 'welcome',
            group: '网站',
            hidden: true,
            title: '欢迎设置',
            fields: ['welcomeChannelId', 'welcomeGreeting']
        },
        {
            id: 'claim',
            group: '网站',
            hidden: true,
            title: '身份组领取',
            fields: ['claimChannelId', 'claimRoles']
        },
        {
            id: 'bot',
            group: 'KOOK',
            title: '机器状态',
            hint: '启动 / 停止 WebSocket Bot，并查看最近日志。',
            fields: [],
            always: true
        },
        {
            id: 'palette',
            group: '网站',
            hidden: true,
            title: '主题配色',
            fields: ['accentColor', 'accentColor2']
        },
        {
            id: 'appearance',
            group: '网站',
            title: '品牌外观',
            hint: '后台 LOGO、登录页标题与副标题。',
            fields: ['logoUrl', 'pageTitle', 'pageSubtitle']
        },
        {
            id: 'github',
            group: '集成',
            hidden: true,
            title: 'GitHub 推送',
            fields: ['githubSecret', 'githubChannelId', 'githubCardNote']
        },
        {
            id: 'post',
            group: '网站',
            hidden: true,
            title: '文章推送',
            fields: ['postChannelId', 'postCardTitle', 'postCardBody']
        },
        {
            id: 'custom',
            group: '网站',
            hidden: true,
            title: '卡片 JSON',
            fields: ['verifyCardJson', 'successCardJson']
        },
        {
            id: 'ai',
            group: '网站',
            hidden: true,
            title: '角色扮演',
            fields: ['aiEnabled', 'aiApiKey', 'aiBaseUrl', 'aiModel', 'aiSystemPrompt', 'aiTemperature', 'aiMaxTokens', 'aiHistoryLimit', 'aiChannelId', 'aiReplyAll']
        },
        {
            id: 'update',
            group: '关于',
            title: '更新检测',
            hint: '检查 GitHub Releases，并在有新版本时更新插件。',
            fields: [],
            always: true
        },
        {
            id: 'about',
            group: '关于',
            title: '关于插件',
            hint: '插件作者、项目地址和更新安全说明。',
            fields: [],
            always: true
        }
    ];

    function optionName(option) {
        var input = option.querySelector('[name]');
        return input ? input.name : '';
    }

    function stripMarkdown(value) {
        return String(value || '')
            .replace(/\*\*|\*|`|~~/g, '')
            .replace(/\[(.*?)\]\((.*?)\)/g, '$1');
    }

    function previewText(value) {
        return stripMarkdown(value)
            .replace(/\{code\}/g, '123456')
            .replace(/\{minutes\}/g, '10')
            .replace(/\{site\}/g, cfg.siteTitle || '站点')
            .replace(/\{ip\}/g, '127.0.0.1')
            .replace(/\{time\}/g, new Date().toLocaleString('zh-CN', { hour12: false }));
    }

    function buildPreview() {
        var wrap = document.createElement('div');
        wrap.className = 'sp-ks-preview';
        wrap.innerHTML = ''
            + '<div class="sp-ks-preview-head"><span>LIVE CARD PREVIEW</span><small>点击卡片打开可视化编辑器</small></div>'
            + '<div class="sp-ks-preview-grid">'
            + '  <article class="sp-ks-kook-card" data-preview="verify"><button type="button" class="sp-ks-edit" data-edit-card="verify">编辑</button><span class="sp-ks-kook-kicker">VERIFY</span><h4></h4><p class="sp-ks-kook-body"></p><div class="sp-ks-kook-context"></div></article>'
            + '  <article class="sp-ks-kook-card" data-preview="success"><button type="button" class="sp-ks-edit" data-edit-card="success">编辑</button><span class="sp-ks-kook-kicker">WELCOME</span><h4></h4><p class="sp-ks-kook-body"></p><div class="sp-ks-kook-context"></div></article>'
            + '</div>';

        return wrap;
    }

    function refreshPreview() {
        var colorInput = field('cardColor');
        var color = colorInput ? colorInput.value : '#FFCDCC';
        var verify = form.querySelector('[data-preview="verify"]');
        var success = form.querySelector('[data-preview="success"]');

        if (!verify || !success) {
            return;
        }

        [verify, success].forEach(function (card) {
            card.style.borderColor = color;
        });

        verify.querySelector('h4').textContent = previewText((field('verifyCardTitle') || {}).value || 'KOOK 登录验证');
        verify.querySelector('.sp-ks-kook-body').textContent = previewText((field('verifyCardContent') || {}).value || '');
        verify.querySelector('.sp-ks-kook-context').textContent = previewText((field('verifyCardContext') || {}).value || '');

        success.querySelector('h4').textContent = previewText((field('successCardTitle') || {}).value || '登录成功');
        success.querySelector('.sp-ks-kook-body').textContent = previewText((field('successCardContent') || {}).value || '');
        success.querySelector('.sp-ks-kook-context').textContent = previewText((field('successCardContext') || {}).value || '');
    }

    // ------------------------------------------------------------------
    // Visual card editor window (KMarkdown toolbar + live KOOK card).
    // ------------------------------------------------------------------

    var editor = null;
    var editorType = 'verify';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function editorVars() {
        return {
            '{code}': '123456',
            '{minutes}': '10',
            '{site}': cfg.siteTitle || '站点',
            '{ip}': '127.0.0.1',
            '{time}': new Date().toLocaleString('zh-CN', { hour12: false }),
            '{user}': '小蛙#3960',
            '{uid}': '78962317',
            '{title}': '示例文章标题',
            '{url}': 'https://example.com/post/1'
        };
    }

    function renderKMarkdown(value) {
        var text = String(value || '');
        var vars = editorVars();
        Object.keys(vars).forEach(function (key) {
            text = text.split(key).join(vars[key]);
        });

        var html = escapeHtml(text);
        html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        html = html.replace(/__([^_]+)__/g, '<u>$1</u>');
        html = html.replace(/~~([^~]+)~~/g, '<del>$1</del>');
        html = html.replace(/\(ins\)([\s\S]*?)\(ins\)/g, '<u>$1</u>');
        html = html.replace(/\(spl\)([\s\S]*?)\(spl\)/g, '<span class="sp-kc-spoiler">$1</span>');
        html = html.replace(/\(emj\)([^(]+)\(emj\)(?:\[[^\]]+\])?/g, '<span class="sp-kc-emoji">:$1:</span>');
        html = html.replace(/\(font\)([^|]+)\|([\s\S]*?)\(font\)/g, '<span style="color:$1">$2</span>');
        html = html.replace(/\(met\)([^)]+)\(met\)/g, '<span class="sp-kc-mention">@$1</span>');
        html = html.replace(/\(chn\)([^)]+)\(chn\)/g, '<span class="sp-kc-mention">#$1</span>');
        html = html.replace(/\(rol\)([^)]+)\(rol\)/g, '<span class="sp-kc-mention">@$1</span>');
        html = html.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1">');
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
        html = html.replace(/^######\s?(.*)$/gm, '<h6>$1</h6>');
        html = html.replace(/^#####\s?(.*)$/gm, '<h5>$1</h5>');
        html = html.replace(/^####\s?(.*)$/gm, '<h4>$1</h4>');
        html = html.replace(/^###\s?(.*)$/gm, '<h3>$1</h3>');
        html = html.replace(/^##\s?(.*)$/gm, '<h2>$1</h2>');
        html = html.replace(/^#\s?(.*)$/gm, '<h1>$1</h1>');
        html = html.replace(/^&gt;\s?(.*)$/gm, '<blockquote>$1</blockquote>');
        html = html.replace(/^- (.*)$/gm, '<span class="sp-kc-li">• $1</span>');
        html = html.replace(/^\d+\. (.*)$/gm, '<span class="sp-kc-li">$&</span>');
        html = html.replace(/^---$/gm, '<hr>');
        html = html.replace(/\n/g, '<br>');

        return html || '<span class="sp-kc-empty">还没有正文</span>';
    }

    function editorField(name) {
        return field(editorType === 'success'
            ? ({ title: 'successCardTitle', body: 'successCardContent', context: 'successCardContext' })[name]
            : ({ title: 'verifyCardTitle', body: 'verifyCardContent', context: 'verifyCardContext' })[name]);
    }

    function setFieldValue(name, value) {
        var input = field(name);
        if (!input) return;
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function ensureEditor() {
        if (editor) return editor;

        editor = document.createElement('div');
        editor.className = 'sp-kc-overlay';
        editor.hidden = true;
        editor.innerHTML = ''
            + '<div class="sp-kc-window" role="dialog" aria-modal="true" aria-labelledby="sp-kc-title">'
            + '  <header class="sp-kc-head"><div><span>KOOK CARD EDITOR</span><h3 id="sp-kc-title">通用卡片消息</h3></div><button type="button" class="sp-kc-close" aria-label="关闭">×</button></header>'
            + '  <div class="sp-kc-main">'
            + '    <div class="sp-kc-form">'
            + '      <div class="sp-kc-tabs"><button type="button" data-card-type="verify">验证码卡片</button><button type="button" data-card-type="success">登录成功卡片</button></div>'
            + '      <div class="sp-kc-color-row"><label>卡片颜色</label><input type="color" class="sp-kc-color" value="#FFCDCC"><input type="text" class="sp-kc-color-text" value="#FFCDCC" maxlength="7"><span class="sp-kc-swatches"><button type="button" data-color="#FFCDCC" style="--sw:#FFCDCC"></button><button type="button" data-color="#5b5bd6" style="--sw:#5b5bd6"></button><button type="button" data-color="#2f9e5f" style="--sw:#2f9e5f"></button><button type="button" data-color="#d99a35" style="--sw:#d99a35"></button><button type="button" data-color="#d9534f" style="--sw:#d9534f"></button><button type="button" data-color="#4a9eff" style="--sw:#4a9eff"></button></span></div>'
            + '      <label class="sp-kc-label" for="sp-kc-title-input">标题</label>'
            + '      <input type="text" id="sp-kc-title-input" class="sp-kc-title" maxlength="100">'
            + '      <div class="sp-kc-label-row"><label class="sp-kc-label" for="sp-kc-body">正文</label><span>KMarkdown</span></div>'
            + '      <div class="sp-kc-toolbar" role="toolbar" aria-label="KMarkdown 工具栏">'
            + '        <button type="button" data-wrap="**|**|粗体" title="粗体">B</button>'
            + '        <button type="button" data-wrap="*|*|斜体" title="斜体"><i>I</i></button>'
            + '        <button type="button" data-wrap="__|__|下划线" title="下划线"><u>U</u></button>'
            + '        <button type="button" data-wrap="~~|~~|删除线" title="删除线"><s>S</s></button>'
            + '        <button type="button" data-wrap="`|`|code" title="行内代码">&lt;/&gt;</button>'
            + '        <button type="button" data-wrap="```|```|代码块" title="代码块">▤</button>'
            + '        <button type="button" data-wrap="[|](https://)|链接" title="链接">↗</button>'
            + '        <button type="button" data-insert="&gt; |引用" title="引用">❝</button>'
            + '        <button type="button" data-insert="&gt;&gt; |引用" title="二级引用">❞</button>'
            + '        <button type="button" data-insert="(spl) |(spl)" title="剧透">◐</button>'
            + '        <button type="button" data-insert="- |列表" title="无序列表">•</button>'
            + '        <button type="button" data-insert="1. |列表" title="有序列表">1.</button>'
            + '        <button type="button" data-insert="# |标题" title="一级标题">H1</button>'
            + '        <button type="button" data-insert="## |标题" title="二级标题">H2</button>'
            + '        <button type="button" data-insert="\n---\n" title="分割线">—</button>'
            + '        <button type="button" data-insert="![图片](https://)" title="图片">▣</button>'
            + '      </div>'
            + '      <textarea id="sp-kc-body" class="sp-kc-body" rows="8"></textarea>'
            + '      <div class="sp-kc-vars"><span>变量</span><button type="button" data-var="{code}" title="验证码">{code}</button><button type="button" data-var="{minutes}" title="有效期分钟">{minutes}</button><button type="button" data-var="{site}" title="站点名">{site}</button><button type="button" data-var="{time}" title="当前时间">{time}</button><button type="button" data-var="{ip}" title="客户端 IP">{ip}</button><button type="button" data-var="{user}" title="KOOK 昵称">{user}</button><button type="button" data-var="{uid}" title="KOOK 用户 ID">{uid}</button><button type="button" data-var="{title}" title="文章标题">{title}</button><button type="button" data-var="{url}" title="文章链接">{url}</button></div>'
            + '      <label class="sp-kc-label" for="sp-kc-context">底部备注</label>'
            + '      <textarea id="sp-kc-context" class="sp-kc-context" rows="3"></textarea>'
            + '      <p class="sp-kc-help">支持 KMarkdown：<code>**粗体**</code> <code>*斜体*</code> <code>~~删除~~</code> <code>`代码`</code> <code>[文字](链接)</code>；卡片模块顺序为 header / section / divider / context。</p>'
            + '      <details class="sp-kc-advanced"><summary>高级：完整卡片结构 JSON</summary><textarea class="sp-kc-json" rows="7" placeholder="留空时使用上方可视化字段；填写后优先使用这里的完整 card 对象"></textarea><p class="sp-kc-help">可填写完整 card 对象，例如 <code>{"type":"card","modules":[...]}</code>。变量仍会替换。</p></details>'
            + '    </div>'
            + '    <div class="sp-kc-preview-pane"><span class="sp-kc-preview-label">LIVE PREVIEW</span><article class="sp-kc-card"><span class="sp-kc-kicker">KOOK · TYPE 10</span><h4 class="sp-kc-preview-title"></h4><div class="sp-kc-preview-body"></div><div class="sp-kc-preview-context"></div></article><p class="sp-kc-preview-note">预览仅为近似效果，实际以 KOOK 客户端渲染为准。</p></div>'
            + '  </div>'
            + '  <footer class="sp-kc-foot"><a href="https://developer.kookapp.cn/doc/kmarkdown" target="_blank" rel="noopener noreferrer">KMarkdown 文档 ↗</a><span></span><button type="button" class="sp-kc-cancel">取消</button><button type="button" class="sp-kc-apply">应用卡片</button></footer>'
            + '</div>';

        document.body.appendChild(editor);

        var body = editor.querySelector('.sp-kc-body');
        var context = editor.querySelector('.sp-kc-context');
        var title = editor.querySelector('.sp-kc-title');
        var color = editor.querySelector('.sp-kc-color');
        var colorText = editor.querySelector('.sp-kc-color-text');
        var rawJson = editor.querySelector('.sp-kc-json');

        function update() {
            var card = editor.querySelector('.sp-kc-card');
            var cardColor = /^#[0-9a-f]{6}$/i.test(colorText.value) ? colorText.value : '#FFCDCC';
            card.style.borderColor = cardColor;
            card.style.setProperty('--sp-card-color', cardColor);
            editor.querySelector('.sp-kc-preview-title').textContent = title.value || '卡片标题';
            editor.querySelector('.sp-kc-preview-body').innerHTML = renderKMarkdown(body.value);
            editor.querySelector('.sp-kc-preview-context').textContent = previewText(context.value).replace(/\n/g, ' · ');
        }

        function insertWrap(before, after, placeholder) {
            var start = body.selectionStart;
            var end = body.selectionEnd;
            var selected = body.value.slice(start, end) || placeholder;
            body.setRangeText(before + selected + after, start, end, 'end');
            body.focus();
            update();
        }

        editor.querySelectorAll('[data-wrap]').forEach(function (button) {
            button.addEventListener('click', function () {
                var parts = button.getAttribute('data-wrap').split('|');
                insertWrap(parts[0], parts[1], parts[2] || '文字');
            });
        });

        editor.querySelectorAll('[data-insert]').forEach(function (button) {
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-insert');
                body.setRangeText(text, body.selectionStart, body.selectionEnd, 'end');
                body.focus();
                update();
            });
        });

        editor.querySelectorAll('[data-var]').forEach(function (button) {
            button.addEventListener('click', function () {
                var text = button.getAttribute('data-var');
                body.setRangeText(text, body.selectionStart, body.selectionEnd, 'end');
                body.focus();
                update();
            });
        });

        color.addEventListener('input', function () {
            colorText.value = color.value.toUpperCase();
            update();
        });
        colorText.addEventListener('input', function () {
            if (/^#[0-9a-f]{6}$/i.test(colorText.value)) {
                color.value = colorText.value;
            }
            update();
        });
        editor.querySelectorAll('[data-color]').forEach(function (button) {
            button.addEventListener('click', function () {
                var value = button.getAttribute('data-color');
                color.value = value;
                colorText.value = value;
                update();
            });
        });
        [title, body, context].forEach(function (input) {
            input.addEventListener('input', update);
        });

        editor.querySelectorAll('[data-card-type]').forEach(function (button) {
            button.addEventListener('click', function () {
                openEditor(button.getAttribute('data-card-type'));
            });
        });
        editor.querySelector('.sp-kc-close').addEventListener('click', closeEditor);
        editor.querySelector('.sp-kc-cancel').addEventListener('click', closeEditor);
        editor.querySelector('.sp-kc-apply').addEventListener('click', function () {
            setFieldValue('cardColor', /^#[0-9a-f]{6}$/i.test(colorText.value) ? colorText.value : '#FFCDCC');
            setFieldValue(editorType === 'success' ? 'successCardTitle' : 'verifyCardTitle', title.value);
            setFieldValue(editorType === 'success' ? 'successCardContent' : 'verifyCardContent', body.value);
            setFieldValue(editorType === 'success' ? 'successCardContext' : 'verifyCardContext', context.value);
            setFieldValue(editorType === 'success' ? 'successCardJson' : 'verifyCardJson', rawJson.value.trim());
            refreshPreview();
            closeEditor();
        });

        editor.addEventListener('click', function (event) {
            if (event.target === editor) closeEditor();
        });

        document.addEventListener('keydown', function (event) {
            if (!editor.hidden && event.key === 'Escape') {
                event.preventDefault();
                closeEditor();
            }
        });

        return editor;
    }

    function openEditor(type) {
        ensureEditor();
        editorType = 'success' === type ? 'success' : 'verify';
        var titleField = editorField('title');
        var bodyField = editorField('body');
        var contextField = editorField('context');
        var colorField = field('cardColor');
        var color = colorField ? colorField.value : '#FFCDCC';

        editor.querySelector('#sp-kc-title').textContent = 'success' === editorType ? '编辑登录成功卡片' : '通用卡片消息';
        editor.querySelectorAll('[data-card-type]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-card-type') === editorType);
        });
        editor.querySelector('.sp-kc-title').value = titleField ? titleField.value : '';
        editor.querySelector('.sp-kc-body').value = bodyField ? bodyField.value : '';
        editor.querySelector('.sp-kc-context').value = contextField ? contextField.value : '';
        editor.querySelector('.sp-kc-json').value = ((field(editorType === 'success' ? 'successCardJson' : 'verifyCardJson') || {}).value || '').trim();
        editor.querySelector('.sp-kc-color').value = color;
        editor.querySelector('.sp-kc-color-text').value = color;
        editor.hidden = false;
        document.body.classList.add('sp-kc-open');
        editor.querySelector('.sp-kc-title').focus();
        editor.querySelector('.sp-kc-preview-title').textContent = titleField ? titleField.value : '';
        editor.querySelector('.sp-kc-preview-body').innerHTML = renderKMarkdown(bodyField ? bodyField.value : '');
        editor.querySelector('.sp-kc-preview-context').textContent = previewText(contextField ? contextField.value : '').replace(/\n/g, ' · ');
        editor.querySelector('.sp-kc-card').style.borderColor = color;
    }

    function closeEditor() {
        if (!editor) return;
        editor.hidden = true;
        document.body.classList.remove('sp-kc-open');
    }

    function buildLogoPreview() {
        var wrap = document.createElement('div');
        wrap.className = 'sp-ks-logo-preview is-single';
        wrap.innerHTML = '<div class="sp-ks-logo-slot is-light"><span>LOGO</span><div class="sp-ks-logo-image" data-logo="light"></div></div>';

        return wrap;
    }

    function refreshLogo() {
        var light = (field('logoUrl') || {}).value || '';
        var initial = (cfg.siteInitial || 'S').toUpperCase();
        var slot = form.querySelector('[data-logo="light"]');

        if (!slot) return;

        slot.innerHTML = '';

        if (light) {
            var img = document.createElement('img');
            img.src = light;
            img.referrerPolicy = 'no-referrer';
            img.alt = '';
            slot.appendChild(img);
        } else {
            var mark = document.createElement('strong');
            mark.textContent = initial;
            slot.appendChild(mark);
        }
    }

    var palettePresets = [
        { name: 'Indigo', accent: '#5b5bd6', accent2: '#7c6cff' },
        { name: 'Ocean', accent: '#0f766e', accent2: '#14b8a6' },
        { name: 'Rose', accent: '#be185d', accent2: '#f472b6' },
        { name: 'Amber', accent: '#b45309', accent2: '#f59e0b' },
        { name: 'Violet', accent: '#7c3aed', accent2: '#a78bfa' },
        { name: 'Slate', accent: '#334155', accent2: '#64748b' }
    ];

    function buildPaletteEditor() {
        var wrap = document.createElement('div');
        wrap.className = 'sp-ks-palette';
        var swatches = palettePresets.map(function (preset, index) {
            return '<button type="button" class="sp-ks-preset" data-preset="' + index + '">'
                + '<span class="sp-ks-preset-dots"><i style="background:' + preset.accent + '"></i><i style="background:' + preset.accent2 + '"></i></span>'
                + '<strong>' + preset.name + '</strong></button>';
        }).join('');

        wrap.innerHTML = ''
            + '<div class="sp-ks-palette-head"><span>COLOR SYSTEM</span><small>主色用于按钮、链接和选中态；辅色用于渐变与高亮。</small></div>'
            + '<div class="sp-ks-presets">' + swatches + '</div>'
            + '<div class="sp-ks-palette-custom">'
            + '  <label><span>主色</span><input type="color" data-palette-color="accentColor"><b></b></label>'
            + '  <label><span>辅色</span><input type="color" data-palette-color="accentColor2"><b></b></label>'
            + '</div>'
            + '<div class="sp-ks-palette-demo"><span class="sp-ks-demo-btn">保存设置</span><a href="#" onclick="return false">链接文字</a><span class="sp-ks-demo-chip">选中态</span></div>';

        return wrap;
    }

    function hexToRgb(hex) {
        var value = String(hex || '').replace('#', '');
        if (!/^[0-9a-f]{6}$/i.test(value)) return '91,91,214';
        return parseInt(value.slice(0, 2), 16) + ',' + parseInt(value.slice(2, 4), 16) + ',' + parseInt(value.slice(4, 6), 16);
    }

    function applyPalettePreview(accent, accent2) {
        var rgb = hexToRgb(accent);
        var rgb2 = hexToRgb(accent2);
        document.documentElement.style.setProperty('--sp-accent', accent);
        document.documentElement.style.setProperty('--sp-accent-2', accent2);
        document.documentElement.style.setProperty('--sp-accent-3', accent2);
        document.documentElement.style.setProperty('--sp-accent-rgb', rgb);
        document.documentElement.style.setProperty('--sp-accent-2-rgb', rgb2);
        document.documentElement.style.setProperty('--sp-sidebar-icon-active', accent2);
        document.documentElement.style.setProperty('--sp-accent-soft', 'rgba(' + rgb + ',.10)');
        document.documentElement.style.setProperty('--sp-sidebar-active', 'rgba(' + rgb + ',.10)');
    }

    function refreshPalette() {
        var editor = form.querySelector('.sp-ks-palette');
        if (!editor) return;
        var accent = ((field('accentColor') || {}).value || '#5b5bd6').toLowerCase();
        var accent2 = ((field('accentColor2') || {}).value || '#7c6cff').toLowerCase();
        var colorInputs = editor.querySelectorAll('[data-palette-color]');
        colorInputs[0].value = accent;
        colorInputs[1].value = accent2;
        colorInputs[0].parentNode.querySelector('b').textContent = accent;
        colorInputs[1].parentNode.querySelector('b').textContent = accent2;
        editor.querySelectorAll('.sp-ks-preset').forEach(function (button) {
            var preset = palettePresets[Number(button.getAttribute('data-preset'))];
            button.classList.toggle('is-active', preset.accent === accent && preset.accent2 === accent2);
        });
        applyPalettePreview(accent, accent2);
    }

    function attachPaletteEditor() {
        var panel = form.querySelector('[data-panel="palette"]');
        if (!panel) return;
        var fields = panel.querySelector('.sp-ks-fields');
        var editor = buildPaletteEditor();
        fields.insertBefore(editor, fields.firstChild);

        editor.querySelectorAll('.sp-ks-preset').forEach(function (button) {
            button.addEventListener('click', function () {
                var preset = palettePresets[Number(button.getAttribute('data-preset'))];
                setFieldValue('accentColor', preset.accent);
                setFieldValue('accentColor2', preset.accent2);
                refreshPalette();
            });
        });

        editor.querySelectorAll('[data-palette-color]').forEach(function (input) {
            input.addEventListener('input', function () {
                setFieldValue(input.getAttribute('data-palette-color'), input.value);
                refreshPalette();
            });
        });
    }

    var presenceEditor = null;

    function ensurePresenceEditor() {
        if (presenceEditor) return presenceEditor;

        presenceEditor = document.createElement('div');
        presenceEditor.className = 'sp-kc-overlay sp-presence-overlay';
        presenceEditor.hidden = true;
        presenceEditor.innerHTML = '<div class="sp-kc-window sp-presence-window"><header class="sp-kc-head"><div><span>RICH PRESENCE</span><h3>在玩状态</h3></div><button type="button" class="sp-kc-close">×</button></header><div class="sp-presence-body"><div class="sp-presence-head"><strong>展示内容</strong><span data-presence-status>未设置</span></div><div class="sp-presence-tabs"><button type="button" data-presence-type="game" class="is-active">玩游戏</button><button type="button" data-presence-type="music">听歌</button></div><div class="sp-presence-pane" data-presence-pane="game"><input data-presence-game placeholder="游戏名称，例如：我的世界"></div><div class="sp-presence-pane" data-presence-pane="music" hidden><input data-presence-song placeholder="歌曲名"><input data-presence-singer placeholder="歌手"><select data-presence-software><option value="cloudmusic">网易云</option><option value="qqmusic">QQ 音乐</option><option value="kugou">酷狗</option></select></div><div class="sp-presence-actions"><button type="button" data-presence-start>开始展示</button><button type="button" data-presence-stop>结束展示</button></div></div><footer class="sp-kc-foot"><span></span><button type="button" class="sp-kc-cancel">关闭</button></footer></div>';
        document.body.appendChild(presenceEditor);

        var mode = 'game';
        var game = presenceEditor.querySelector('[data-presence-game]');
        var song = presenceEditor.querySelector('[data-presence-song]');
        var singer = presenceEditor.querySelector('[data-presence-singer]');
        var software = presenceEditor.querySelector('[data-presence-software]');
        var status = presenceEditor.querySelector('[data-presence-status]');

        function sync() {
            presenceEditor.querySelectorAll('[data-presence-pane]').forEach(function (pane) {
                pane.hidden = pane.getAttribute('data-presence-pane') !== mode;
            });
            presenceEditor.querySelectorAll('[data-presence-type]').forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-presence-type') === mode);
            });
        }

        presenceEditor.querySelectorAll('[data-presence-type]').forEach(function (button) {
            button.addEventListener('click', function () {
                mode = button.getAttribute('data-presence-type');
                sync();
            });
        });

        function request(action) {
            var data = new FormData();
            data.append('_', cfg.presenceToken);
            data.append('action', action);
            data.append('type', mode);
            data.append('game', game.value.trim());
            data.append('song', song.value.trim());
            data.append('singer', singer.value.trim());
            data.append('software', software.value);
            status.textContent = '处理中…';
            fetch(cfg.presenceEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.error) || '操作失败');
                    status.textContent = 'stop' === action ? '已结束' : ('music' === mode ? '正在听歌' : '正在玩游戏');
                })
                .catch(function (error) { status.textContent = error.message || '操作失败'; });
        }

        presenceEditor.querySelector('[data-presence-start]').addEventListener('click', function () { request('start'); });
        presenceEditor.querySelector('[data-presence-stop]').addEventListener('click', function () { request('stop'); });
        presenceEditor.querySelector('.sp-kc-close').addEventListener('click', function () { presenceEditor.hidden = true; document.body.classList.remove('sp-kc-open'); });
        presenceEditor.querySelector('.sp-kc-cancel').addEventListener('click', function () { presenceEditor.hidden = true; document.body.classList.remove('sp-kc-open'); });
        presenceEditor.addEventListener('click', function (event) { if (event.target === presenceEditor) { presenceEditor.hidden = true; document.body.classList.remove('sp-kc-open'); } });
        presenceEditor.__open = function () {
            sync();
            presenceEditor.hidden = false;
            document.body.classList.add('sp-kc-open');
        };
        return presenceEditor;
    }

    function openPresenceEditor() {
        ensurePresenceEditor().__open();
    }

    var aiEditor = null;

    function readAiSetting(name) {
        var inputs = document.querySelectorAll('[name="' + name + '"]');
        if (!inputs.length) return '';

        for (var i = 0; i < inputs.length; i++) {
            if ('radio' === inputs[i].type) {
                if (inputs[i].checked) return inputs[i].value;
            } else {
                return inputs[i].value;
            }
        }

        return '';
    }

    function writeAiSetting(name, value) {
        document.querySelectorAll('[name="' + name + '"]').forEach(function (input) {
            if ('radio' === input.type) {
                input.checked = input.value === value;
            } else {
                input.value = value;
            }
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function openAiEditor() {
        if (!aiEditor) {
            aiEditor = document.createElement('div');
            aiEditor.className = 'sp-kc-overlay sp-ai-overlay';
            aiEditor.hidden = true;
            aiEditor.innerHTML = '<div class="sp-kc-window sp-ai-window">'
                + '<header class="sp-kc-head"><div><span>DEEPSEEK ROLEPLAY</span><h3>角色扮演</h3></div><button type="button" class="sp-kc-close">×</button></header>'
                + '<div class="sp-ai-main"><div class="sp-ai-form">'
                + '  <label class="sp-kc-label">状态</label><select class="sp-ai-enabled"><option value="0">关闭</option><option value="1">启用</option></select>'
                + '  <label class="sp-kc-label">DeepSeek API Key</label><input type="text" class="sp-ai-key" placeholder="sk-...">'
                + '  <div class="sp-ai-two"><label><span>API 地址</span><input type="text" class="sp-ai-base" placeholder="https://api.deepseek.com"></label><label><span>模型</span><input type="text" class="sp-ai-model" placeholder="deepseek-chat"></label></div>'
                + '  <label class="sp-kc-label">角色设定 / System Prompt</label><textarea class="sp-ai-prompt" rows="9"></textarea>'
                + '  <div class="sp-ai-grid"><label><span>Temperature</span><input type="text" class="sp-ai-temp"></label><label><span>最大 Token</span><input type="text" class="sp-ai-max"></label><label><span>上下文条数</span><input type="text" class="sp-ai-history"></label></div>'
                + '  <div class="sp-ai-grid"><label><span>AI 频道 ID</span><input type="text" class="sp-ai-channel" placeholder="可选"></label><label><span>频道内自动回复</span><select class="sp-ai-reply-all"><option value="0">关闭</option><option value="1">开启</option></select></label></div>'
                + '  <p class="sp-ai-hint">在 KOOK 中 @机器人 即可对话；开启频道内自动回复后，指定频道内无需 @ 也会回复。</p>'
                + '</div><aside class="sp-ai-side">'
                + '  <div class="sp-ai-side-head"><span>TEST / LOGS</span><strong>测试与运行日志</strong></div>'
                + '  <div class="sp-ai-chat"><div class="sp-ai-chat-head"><i>宅</i><div><strong>宅の家</strong><span>机器人 · 测试会话</span></div></div><div class="sp-ai-reply" data-ai-reply>保存设置后，点击下方按钮测试 DeepSeek 回复。</div>'
                + '    <div class="sp-ai-test-row"><input type="text" class="sp-ai-test-input" placeholder="输入测试消息"><button type="button" data-ai-modal-test>发送测试</button></div>'
                + '  </div>'
                + '  <div class="sp-ai-log-head"><span>运行日志</span><button type="button" data-ai-log-refresh>刷新</button></div>'
                + '  <pre class="sp-ai-logs" data-ai-logs>读取中…</pre>'
                + '</aside></div>'
                + '<footer class="sp-kc-foot"><span></span><button type="button" class="sp-kc-cancel">关闭</button></footer></div>';
            document.body.appendChild(aiEditor);

            var enabled = aiEditor.querySelector('.sp-ai-enabled');
            var key = aiEditor.querySelector('.sp-ai-key');
            var base = aiEditor.querySelector('.sp-ai-base');
            var model = aiEditor.querySelector('.sp-ai-model');
            var prompt = aiEditor.querySelector('.sp-ai-prompt');
            var temp = aiEditor.querySelector('.sp-ai-temp');
            var max = aiEditor.querySelector('.sp-ai-max');
            var history = aiEditor.querySelector('.sp-ai-history');
            var aiChannel = aiEditor.querySelector('.sp-ai-channel');
            var aiReplyAll = aiEditor.querySelector('.sp-ai-reply-all');
            var testInput = aiEditor.querySelector('.sp-ai-test-input');
            var reply = aiEditor.querySelector('[data-ai-reply]');
            var logs = aiEditor.querySelector('[data-ai-logs]');

            function collectAi() {
                return {
                    aiEnabled: enabled.value,
                    aiApiKey: key.value,
                    aiBaseUrl: base.value,
                    aiModel: model.value,
                    aiSystemPrompt: prompt.value,
                    aiTemperature: temp.value,
                    aiMaxTokens: max.value,
                    aiHistoryLimit: history.value,
                    aiChannelId: aiChannel.value,
                    aiReplyAll: aiReplyAll.value
                };
            }

            function sync() {
                var values = collectAi();
                Object.keys(values).forEach(function (name) { writeAiSetting(name, values[name]); });
            }

            function saveAiServer() {
                var values = collectAi();
                var data = new FormData();
                data.append('_', cfg.aiToken);
                data.append('action', 'save');
                Object.keys(values).forEach(function (name) { data.append(name, values[name]); });
                return fetch(cfg.aiEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (!payload || !payload.ok) throw new Error((payload && payload.error) || '保存失败');
                        sync();
                        return payload;
                    });
            }

            function loadAiServer() {
                var data = new FormData();
                data.append('_', cfg.aiToken);
                data.append('action', 'get');
                fetch(cfg.aiEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (!payload || !payload.ok || !payload.settings) return;
                        var s = payload.settings;
                        enabled.value = s.aiEnabled || '0';
                        key.value = s.aiApiKey || '';
                        base.value = s.aiBaseUrl || 'https://api.deepseek.com';
                        model.value = s.aiModel || 'deepseek-chat';
                        prompt.value = s.aiSystemPrompt || '';
                        temp.value = s.aiTemperature || '0.8';
                        max.value = s.aiMaxTokens || '180';
                        history.value = s.aiHistoryLimit || '6';
                        aiChannel.value = s.aiChannelId || '';
                        aiReplyAll.value = s.aiReplyAll || '0';
                    })
                    .catch(function () {});
            }

            [enabled, key, base, model, prompt, temp, max, history, aiChannel, aiReplyAll].forEach(function (input) {
                input.addEventListener('change', sync);
                input.addEventListener('blur', sync);
            });

            function loadLogs() {
                logs.textContent = '读取中…';
                var data = new FormData();
                data.append('_', cfg.aiToken);
                data.append('action', 'logs');
                fetch(cfg.aiEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (!payload || !payload.ok) throw new Error((payload && payload.error) || '读取日志失败');
                        var rows = payload.logs || [];
                        logs.textContent = rows.length ? rows.join('\n') : '还没有 AI 日志。';
                        logs.scrollTop = logs.scrollHeight;
                    })
                    .catch(function (error) { logs.textContent = error.message || '读取日志失败'; });
            }

            aiEditor.querySelector('[data-ai-log-refresh]').addEventListener('click', loadLogs);

            var testButton = aiEditor.querySelector('[data-ai-modal-test]');
            testButton.addEventListener('click', function () {
                sync();
                var data = new FormData();
                data.append('_', cfg.aiToken);
                data.append('prompt', testInput.value.trim() || '你好，请用一句话确认你已经准备好进行角色扮演。');
                testButton.disabled = true;
                testButton.textContent = '测试中…';
                reply.textContent = '正在保存设置并请求 DeepSeek…';
                saveAiServer()
                    .then(function () {
                        return fetch(cfg.aiEndpoint, { method: 'POST', credentials: 'same-origin', body: data });
                    })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (!payload || !payload.ok) throw new Error((payload && payload.error) || '测试失败');
                        reply.textContent = payload.reply || '没有返回内容';
                        loadLogs();
                    })
                    .catch(function (error) { reply.textContent = error.message || '测试失败'; })
                    .then(function () { testButton.disabled = false; testButton.textContent = '发送测试'; });
            });

            aiEditor.querySelector('.sp-kc-close').addEventListener('click', function () { sync(); saveAiServer().catch(function () {}); aiEditor.hidden = true; document.body.classList.remove('sp-kc-open'); });
            aiEditor.querySelector('.sp-kc-cancel').addEventListener('click', function () { sync(); saveAiServer().catch(function () {}); aiEditor.hidden = true; document.body.classList.remove('sp-kc-open'); });
            aiEditor.addEventListener('click', function (event) { if (event.target === aiEditor) { aiEditor.hidden = true; document.body.classList.remove('sp-kc-open'); } });

            aiEditor.__sync = function () {
                enabled.value = readAiSetting('aiEnabled') || '0';
                key.value = readAiSetting('aiApiKey');
                base.value = readAiSetting('aiBaseUrl') || 'https://api.deepseek.com';
                model.value = readAiSetting('aiModel') || 'deepseek-chat';
                prompt.value = readAiSetting('aiSystemPrompt');
                temp.value = readAiSetting('aiTemperature') || '0.8';
                max.value = readAiSetting('aiMaxTokens') || '180';
                history.value = readAiSetting('aiHistoryLimit') || '6';
                aiChannel.value = readAiSetting('aiChannelId');
                aiReplyAll.value = readAiSetting('aiReplyAll') || '0';
                reply.textContent = '保存设置后，点击下方按钮测试 DeepSeek 回复。';
                testInput.value = '';
                loadAiServer();
                loadLogs();
            };
        }

        aiEditor.__sync();
        aiEditor.hidden = false;
        document.body.classList.add('sp-kc-open');
    }

    function buildAiPanel(panel) {
        var wrap = document.createElement('div');
        wrap.className = 'sp-ai-test';
        wrap.innerHTML = '<div><strong>连接测试</strong><span>向 DeepSeek 发送一条测试消息，不会发送到 KOOK。</span></div><button type="button" data-ai-test>测试 AI</button><p data-ai-status></p>';
        panel.querySelector('.sp-ks-fields').appendChild(wrap);

        var status = wrap.querySelector('[data-ai-status]');
        wrap.querySelector('[data-ai-test]').addEventListener('click', function () {
            var button = this;
            var data = new FormData();
            data.append('_', cfg.aiToken);
            data.append('prompt', '你好，请用一句话确认你已经准备好进行角色扮演。');
            button.disabled = true;
            button.textContent = '测试中…';
            status.textContent = '';
            fetch(cfg.aiEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.error) || '测试失败');
                    status.textContent = payload.reply;
                })
                .catch(function (error) { status.textContent = error.message || '测试失败'; })
                .then(function () { button.disabled = false; button.textContent = '测试 AI'; });
        });
    }

    function buildAboutPanel(panel) {
        var wrap = document.createElement('div');
        wrap.className = 'sp-about';
        wrap.innerHTML = '<div class="sp-about-author"><img src="https://github.com/aayorishy.png" alt="aayorishy" referrerpolicy="no-referrer"><div><span>MoePanel · 插件作者</span><strong>ayorishy</strong><p>面向 Typecho 的 KOOK 一体化可视化控制台，版本 ' + (cfg.version || '1.0.0') + '。</p></div><em>v' + (cfg.version || '1.0.0') + '</em></div>'
            + '<div class="sp-about-links"><a href="https://github.com/aayorishy" target="_blank" rel="noopener noreferrer">个人主页 ↗</a><a href="https://github.com/aayorishy/Typecho-MoePanel" target="_blank" rel="noopener noreferrer">GitHub 仓库 ↗</a><a href="https://github.com/aayorishy/Typecho-MoePanel/releases" target="_blank" rel="noopener noreferrer">Releases ↗</a><a href="https://github.com/aayorishy/Typecho-MoePanel/issues" target="_blank" rel="noopener noreferrer">反馈问题 ↗</a></div>'
            + '<div class="sp-about-note"><strong>喜欢 MoePanel？</strong><span>去 GitHub 点一个 Star，或者把它推荐给还在使用原生后台的朋友。遇到问题可以提交 Issue。</span></div>'
            + '<div class="sp-about-security"><strong>更新安全检查</strong><span>更新包通过 HTTPS 下载，并使用 GitHub Release 提供的 SHA-256 摘要校验；摘要不匹配时会拒绝安装。</span></div>';
        panel.querySelector('.sp-ks-fields').appendChild(wrap);
    }

    function buildUpdatePanel(panel) {
        var wrap = document.createElement('div');
        wrap.className = 'sp-update';
        wrap.innerHTML = '<div class="sp-update-card"><div class="sp-update-head"><span>GITHUB RELEASES</span><strong>版本更新</strong></div><div class="sp-update-version"><div><span>当前版本</span><strong data-current-version></strong></div><div><span>最新版本</span><strong data-latest-version>-</strong></div></div><div class="sp-update-notes" data-update-notes>点击检查更新，从 GitHub Releases 获取最新版本。</div><div class="sp-update-actions"><button type="button" data-update-check>检查更新</button><button type="button" data-update-install hidden>自动更新</button><a href="https://github.com/aayorishy/Typecho-MoePanel/releases" target="_blank" rel="noopener noreferrer">查看 Releases ↗</a><span data-update-status></span></div></div>';
        panel.querySelector('.sp-ks-fields').appendChild(wrap);

        var current = wrap.querySelector('[data-current-version]');
        var latest = wrap.querySelector('[data-latest-version]');
        var notes = wrap.querySelector('[data-update-notes]');
        var status = wrap.querySelector('[data-update-status]');
        var install = wrap.querySelector('[data-update-install]');
        current.textContent = cfg.version || '-';
        var release = null;

        wrap.querySelector('[data-update-check]').addEventListener('click', function () {
            var data = new FormData();
            data.append('_', cfg.updateToken);
            data.append('action', 'check');
            status.textContent = '检查中…';
            var updateAbort = window.AbortController ? new AbortController() : null;
            var updateTimeout = window.setTimeout(function () {
                if (updateAbort) updateAbort.abort();
                status.textContent = '检查超时，请稍后重试';
            }, 10000);
            fetch(cfg.updateEndpoint, { method: 'POST', credentials: 'same-origin', body: data, signal: updateAbort ? updateAbort.signal : undefined })
                .then(function (response) { window.clearTimeout(updateTimeout); return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.error) || '检查失败');
                    release = payload.result || {};
                    latest.textContent = release.latest || '-';
                    notes.textContent = release.notes || (release.has_update ? '发现新版本' : '已经是最新版本');
                    install.hidden = !release.has_update || !release.download;
                    status.textContent = release.has_update
                        ? ('发现可更新版本' + (release.digest ? ' · 已启用 SHA-256 校验' : ' · 使用 HTTPS 下载'))
                        : '已是最新版本';
                })
                .catch(function (error) { window.clearTimeout(updateTimeout); status.textContent = error.message || '检查失败'; });
        });

        install.addEventListener('click', function () {
            if (!release || !release.download) return;
            var data = new FormData();
            data.append('_', cfg.updateToken);
            data.append('action', 'install');
            data.append('url', release.download);
            data.append('digest', release.digest || '');
            install.disabled = true;
            install.textContent = '更新中…';
            status.textContent = '正在下载并安装…';
            fetch(cfg.updateEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.error) || '更新失败');
                    status.textContent = '更新完成，正在刷新…';
                    window.setTimeout(function () { location.reload(); }, 1200);
                })
                .catch(function (error) {
                    install.disabled = false;
                    install.textContent = '自动更新';
                    status.textContent = error.message || '更新失败';
                });
        });
    }

    function buildBotPanel(panel) {
        var wrap = document.createElement('div');
        wrap.className = 'sp-ks-bot';
        wrap.innerHTML = ''
            + '<div class="sp-ks-bot-head"><span>WEBSOCKET BOT</span><strong data-bot-state>检测中…</strong></div>'
            + '<div class="sp-bot-profile" data-bot-profile hidden><img alt="" referrerpolicy="no-referrer"><div><strong data-bot-name>-</strong><span data-bot-id>-</span></div><em data-bot-live>离线</em></div>'
            + '<div class="sp-ks-server-grid">'
            + '  <div><span>系统</span><strong data-server="os">-</strong></div>'
            + '  <div><span>PHP</span><strong data-server="php">-</strong></div>'
            + '  <div><span>负载</span><strong data-server="load">-</strong></div>'
            + '  <div><span>内存</span><strong data-server="memory">-</strong></div>'
            + '  <div><span>磁盘</span><strong data-server="disk">-</strong></div>'
            + '  <div><span>运行时长</span><strong data-server="uptime">-</strong></div>'
            + '</div>'
            + '<div class="sp-ks-bot-actions"><button type="button" data-bot-action="start">启用</button><button type="button" data-bot-action="stop">停止</button><button type="button" data-bot-action="status">刷新</button></div>'
            + '<pre class="sp-ks-bot-log" data-bot-log>暂无日志</pre>'
            + '<p class="sp-ks-bot-hint">进程方式启动；生产环境建议用 systemd / supervisor / screen 保活。</p>';
        panel.querySelector('.sp-ks-fields').appendChild(wrap);

        var state = wrap.querySelector('[data-bot-state]');
        var log = wrap.querySelector('[data-bot-log]');

        function apply(result) {
            if (!result) return;
            state.textContent = result.running ? '运行中' : '未运行';
            state.classList.toggle('is-on', !!result.running);
            log.textContent = result.log || '暂无日志';

            var profile = wrap.querySelector('[data-bot-profile]');

            if (profile && result.bot) {
                profile.hidden = false;
                var avatar = profile.querySelector('img');
                avatar.src = result.bot.avatar || '';
                avatar.alt = result.bot.username || 'KOOK Bot';
                profile.querySelector('[data-bot-name]').textContent = result.bot.username || 'KOOK Bot';
                profile.querySelector('[data-bot-id]').textContent = 'Bot ID ' + (result.bot.id || '-');
                var live = profile.querySelector('[data-bot-live]');
                live.textContent = result.bot.online ? '在线' : '离线';
                live.classList.toggle('is-on', !!result.bot.online);
            }

            if (result.server) {
                if (result.uptime) result.server.uptime = result.uptime;

                Object.keys(result.server).forEach(function (key) {
                    var cell = wrap.querySelector('[data-server="' + key + '"]');
                    if (cell) cell.textContent = result.server[key];
                });
            }
        }

        function request(action) {
            if (!cfg.botEndpoint || !cfg.botToken) {
                state.textContent = '接口未配置';
                return;
            }

            var data = new FormData();
            data.append('action', action);
            data.append('_', cfg.botToken);
            state.textContent = '处理中…';

            fetch(cfg.botEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (response) {
                return response.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (error) {
                        if (/<!doctype|<html|typecho-login/i.test(text)) {
                            throw new Error('登录状态可能已失效，请刷新后台后重试');
                        }

                        throw new Error('服务器返回异常响应（HTTP ' + response.status + '），请查看 PHP 错误日志');
                    }
                });
            }).then(function (payload) {
                if (!payload || !payload.ok) {
                    throw new Error((payload && payload.error) || '操作失败');
                }
                apply(payload.result);
            }).catch(function (error) {
                state.textContent = '操作失败';
                log.textContent = error.message || '请稍后重试';
            });
        }

        wrap.querySelectorAll('[data-bot-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                request(button.getAttribute('data-bot-action'));
            });
        });

        request('status');
    }

    var directoryData = null;

    function attachChannelSelect(name) {
        var input = field(name);

        if (!input || input.dataset.spChannelSelect || !directoryData || !directoryData.channels) {
            return;
        }

        input.dataset.spChannelSelect = '1';
        var select = document.createElement('select');
        select.className = 'sp-ks-channel-select';
        select.innerHTML = '<option value="">选择频道</option>';

        directoryData.channels.forEach(function (channel) {
            if (channel.isCategory) return;
            var option = document.createElement('option');
            option.value = channel.id;
            option.textContent = channel.name;
            select.appendChild(option);
        });

        if (input.value && !directoryData.channels.some(function (channel) { return channel.id === input.value; })) {
            var current = document.createElement('option');
            current.value = input.value;
            current.textContent = '当前频道 ' + input.value;
            select.appendChild(current);
        }

        select.value = input.value;
        input.style.display = 'none';
        input.parentNode.insertBefore(select, input.nextSibling);
        select.addEventListener('change', function () {
            setFieldValue(name, select.value);
        });
    }

    function populateDirectoryDatalists() {
        if (!directoryData) return;
        var channelList = document.getElementById('sp-claim-channel-list');
        var welcomeChannelList = document.getElementById('sp-welcome-channel-list');
        var roleList = document.getElementById('sp-role-list');
        var userList = document.getElementById('sp-user-list');

        if (!userList) {
            userList = document.createElement('datalist');
            userList.id = 'sp-user-list';
            form.appendChild(userList);
        }

        var ownerInput = field('ownerKookId');
        if (ownerInput && ownerInput.__syncPicker) ownerInput.__syncPicker();
        userList.innerHTML = '';
        directoryData.users.forEach(function (user) {
            var option = document.createElement('option');
            option.value = user.id;
            option.label = user.name;
            userList.appendChild(option);
        });

        [channelList, welcomeChannelList].forEach(function (list) {
            if (!list) return;
            list.innerHTML = '';
            directoryData.channels.forEach(function (channel) {
                if (channel.isCategory) return;
                var option = document.createElement('option');
                option.value = channel.id;
                option.label = channel.name;
                list.appendChild(option);
            });
        });

        if (roleList) {
            roleList.innerHTML = '';
            directoryData.roles.forEach(function (role) {
                var option = document.createElement('option');
                option.value = role.id;
                option.label = role.name;
                roleList.appendChild(option);
            });
        }
    }

    function attachGuildSelect(guilds) {
        var input = field('guildId');

        if (!input || !guilds || !guilds.length || input.dataset.spGuildSelect) return;

        input.dataset.spGuildSelect = '1';
        var select = document.createElement('select');
        select.className = 'sp-ks-channel-select';
        select.innerHTML = '<option value="">选择服务器</option>';

        guilds.forEach(function (guild) {
            var option = document.createElement('option');
            option.value = String(guild.id ?? '');
            option.textContent = String(guild.name ?? guild.id ?? '');
            select.appendChild(option);
        });

        select.value = input.value;
        input.style.display = 'none';
        input.parentNode.insertBefore(select, input.nextSibling);
        select.addEventListener('change', function () {
            setFieldValue('guildId', select.value);
            loadDirectory();
        });
    }

    function loadDirectory() {
        var status = form.querySelector('[data-directory-status]');
        var guildId = ((field('guildId') || {}).value || '').trim();

        if (!cfg.directoryEndpoint || !cfg.directoryToken) {
            if (status) status.textContent = '目录接口未配置';
            return;
        }

        if (status) status.textContent = '读取中…';

        var data = new FormData();
        data.append('_', cfg.directoryToken);
        data.append('guild_id', guildId);

        fetch(cfg.directoryEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        }).then(function (response) {
            return response.text().then(function (text) {
                try { return JSON.parse(text); } catch (error) { throw new Error('服务器返回异常响应'); }
            });
        }).then(function (payload) {
            if (!payload || !payload.ok) {
                throw new Error((payload && payload.error) || '读取失败');
            }

            directoryData = payload.directory || null;

            if (payload.guilds) {
                attachGuildSelect(payload.guilds);
            }

            if (!directoryData) {
                if (status) status.textContent = payload.guilds && payload.guilds.length ? '请先选择服务器' : '没有读到服务器目录';
                return;
            }

            if (status) {
                status.textContent = '频道 ' + directoryData.channels.length + ' · 身份组 ' + directoryData.roles.length + ' · 成员 ' + directoryData.users.length;
            }

            ['welcomeChannelId', 'claimChannelId', 'uploadChannelId', 'githubChannelId'].forEach(attachChannelSelect);
            populateDirectoryDatalists();
            document.dispatchEvent(new Event('sp-directory-ready'));
        }).catch(function (error) {
            if (status) status.textContent = error.message || '读取失败';
        });
    }

    var welcomeEditor = null;

    function ensureWelcomeEditor() {
        if (welcomeEditor) return welcomeEditor;

        welcomeEditor = document.createElement('div');
        welcomeEditor.className = 'sp-kc-overlay sp-welcome-overlay';
        welcomeEditor.hidden = true;
        welcomeEditor.innerHTML = '<div class="sp-kc-window sp-welcome-window">'
            + '<header class="sp-kc-head"><div><span>WELCOME MESSAGE</span><h3>欢迎设置</h3></div><button type="button" class="sp-kc-close">×</button></header>'
            + '<div class="sp-welcome-main"><div class="sp-welcome-form">'
            + '  <label class="sp-kc-label">欢迎频道</label><div class="sp-picker-field"><button type="button" class="sp-picker-display">选择欢迎频道</button><input type="hidden" class="sp-welcome-channel"><div class="sp-picker-dropdown" hidden></div></div>'
            + '  <div class="sp-welcome-section">欢迎文字</div>'
            + '  <label class="sp-kc-label">新成员加入时发送的纯文字消息</label>'
            + '  <div class="sp-kc-toolbar"><button type="button" data-wwrap="**|**|粗体">B</button><button type="button" data-wwrap="*|*|斜体"><i>I</i></button><button type="button" data-wwrap="__|__|下划线"><u>U</u></button><button type="button" data-wwrap="~~|~~|删除线"><s>S</s></button><button type="button" data-wwrap="`|`|代码">&lt;/&gt;</button><button type="button" data-wwrap="[|](https://)|链接">↗</button><button type="button" data-wwrap="```|```|代码块">▤</button><button type="button" data-winsert="\n(spl)\n">◐</button><button type="button" data-winsert="\n---\n">—</button><button type="button" data-wvar="{user}">{user}</button><button type="button" data-wvar="{site}">{site}</button><button type="button" data-wvar="{count}">{count}</button></div>'
            + '  <textarea class="sp-welcome-text" rows="9" placeholder="欢迎 {user} 宝宝 ๑•́ㅅ•̀๑&#10;&#10;( 欢迎小宝 )&#10;( 玩的开心 )"></textarea>'
            + '  <div class="sp-kc-vars"><span>变量</span><button type="button" data-wvar="{user}">{user}</button><button type="button" data-wvar="{site}">{site}</button><button type="button" data-wvar="{count}">{count}</button></div>'
            + '  <p class="sp-welcome-hint">{user} 会替换为真正的 KOOK mention；{site} 为站点名称；{count} 为成员数量。</p>'
            + '  <button type="button" class="sp-welcome-test">保存并发送测试欢迎</button>'
            + '</div><aside class="sp-welcome-preview-pane"><span class="sp-kc-preview-label">LIVE PREVIEW</span>'
            + '  <div class="sp-welcome-chat"><div class="sp-welcome-chat-head"><i>宅</i><strong>宅の家</strong><span>机器人 · 刚刚</span></div><div class="sp-welcome-text-preview"></div></div>'
            + '  <p class="sp-kc-preview-note">实际发送为普通 KMarkdown 文字消息，不包含图片或卡片。</p>'
            + '</aside></div>'
            + '<footer class="sp-kc-foot"><span></span><button type="button" class="sp-kc-cancel">取消</button><button type="button" class="sp-kc-apply">应用欢迎设置</button></footer></div>';
        document.body.appendChild(welcomeEditor);

        var channel = welcomeEditor.querySelector('.sp-welcome-channel');
        var text = welcomeEditor.querySelector('.sp-welcome-text');
        var preview = welcomeEditor.querySelector('.sp-welcome-text-preview');
        setupChannelPicker(channel);

        function update() {
            var value = text.value || '';
            var sample = value
                .replace(/\{user\}/g, '@小蛙')
                .replace(/\{username\}/g, '小蛙#3960')
                .replace(/\{site\}/g, cfg.siteTitle || '站点')
                .replace(/\{count\}/g, '7');
            preview.innerHTML = sample.trim() ? renderKMarkdown(sample) : '<span class="sp-preview-empty">欢迎文字预览</span>';
        }

        [channel, text].forEach(function (input) { input.addEventListener('input', update); });

        welcomeEditor.querySelectorAll('[data-wwrap]').forEach(function (button) {
            button.addEventListener('click', function () {
                var parts = button.getAttribute('data-wwrap').split('|');
                var start = text.selectionStart;
                var end = text.selectionEnd;
                var selected = text.value.slice(start, end) || parts[2] || '文字';
                text.setRangeText(parts[0] + selected + parts[1], start, end, 'end');
                text.focus();
                update();
            });
        });

        welcomeEditor.querySelectorAll('[data-winsert]').forEach(function (button) {
            button.addEventListener('click', function () {
                text.setRangeText(button.getAttribute('data-winsert'), text.selectionStart, text.selectionEnd, 'end');
                text.focus();
                update();
            });
        });

        welcomeEditor.querySelectorAll('[data-wvar]').forEach(function (button) {
            button.addEventListener('click', function () {
                text.setRangeText(button.getAttribute('data-wvar'), text.selectionStart, text.selectionEnd, 'end');
                text.focus();
                update();
            });
        });

        function apply() {
            setFieldValue('welcomeChannelId', channel.value.trim());
            setFieldValue('welcomeGreeting', text.value);
        }

        welcomeEditor.querySelector('.sp-kc-apply').addEventListener('click', function () {
            apply();
            requestSave(true);
            closeWelcomeEditor();
        });

        welcomeEditor.querySelector('.sp-welcome-test').addEventListener('click', function () {
            var button = this;
            apply();
            requestSave(true);
            button.disabled = true;
            button.textContent = '发送中…';
            window.setTimeout(function () {
                var data = new FormData();
                data.append('_', cfg.welcomeToken);
                fetch(cfg.welcomeEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                    .then(function (response) { return response.json(); })
                    .then(function (payload) {
                        if (!payload || !payload.ok) throw new Error((payload && payload.error) || '发送失败');
                        showToast(payload.message || '测试欢迎已发送', 'info');
                    })
                    .catch(function (error) { showToast(error.message || '发送失败', 'error'); })
                    .then(function () { button.disabled = false; button.textContent = '保存并发送测试欢迎'; });
            }, 600);
        });

        welcomeEditor.querySelector('.sp-kc-close').addEventListener('click', closeWelcomeEditor);
        welcomeEditor.querySelector('.sp-kc-cancel').addEventListener('click', closeWelcomeEditor);
        welcomeEditor.addEventListener('click', function (event) { if (event.target === welcomeEditor) closeWelcomeEditor(); });
        document.addEventListener('keydown', function (event) { if (!welcomeEditor.hidden && 'Escape' === event.key) closeWelcomeEditor(); });

        welcomeEditor.__open = function () {
            channel.value = ((field('welcomeChannelId') || {}).value || '').trim();
            text.value = ((field('welcomeGreeting') || {}).value || '').trim();
            if (channel.__syncPicker) channel.__syncPicker();
            channel.focus();
            update();
            welcomeEditor.hidden = false;
            document.body.classList.add('sp-kc-open');
        };

        return welcomeEditor;
    }

    function openWelcomeEditor() {
        ensureWelcomeEditor().__open();
    }

    function closeWelcomeEditor() {
        if (!welcomeEditor) return;
        welcomeEditor.hidden = true;
        document.body.classList.remove('sp-kc-open');
    }

    var customEditor = null;

    function ensureInviteEditor() {
        if (inviteEditor) return inviteEditor;

        inviteEditor = document.createElement('div');
        inviteEditor.className = 'sp-kc-overlay sp-invite-overlay';
        inviteEditor.hidden = true;
        inviteEditor.innerHTML = ''
            + '<div class="sp-kc-window sp-invite-window">'
            + '  <header class="sp-kc-head"><div><span>INVITE MANAGER</span><h3>邀请管理</h3></div><button type="button" class="sp-kc-close" aria-label="关闭">×</button></header>'
            + '  <div class="sp-invite-body">'
            + '    <div class="sp-invite-form"><div class="sp-invite-form-head"><strong>创建邀请</strong><span>生成服务器或频道邀请链接</span></div>'
            + '      <label class="sp-kc-label">邀请频道（可选）</label><div class="sp-picker-field"><button type="button" class="sp-picker-display">使用服务器默认邀请</button><input type="hidden" class="sp-invite-channel"><div class="sp-picker-dropdown" hidden></div></div>'
            + '      <div class="sp-invite-grid"><div><label class="sp-kc-label">有效期</label><select class="sp-invite-duration"><option value="0">永久</option><option value="1800">30 分钟</option><option value="3600">1 小时</option><option value="21600">6 小时</option><option value="43200">12 小时</option><option value="86400">1 天</option><option value="604800" selected>7 天</option></select></div><div><label class="sp-kc-label">使用次数</label><select class="sp-invite-times"><option value="-1">不限</option><option value="1">1 次</option><option value="5">5 次</option><option value="10">10 次</option><option value="25">25 次</option><option value="50">50 次</option><option value="100">100 次</option></select></div></div>'
            + '      <div class="sp-invite-submit"><button type="button" class="sp-invite-create">创建邀请链接</button><span data-invite-status></span></div>'
            + '    </div>'
            + '    <div class="sp-invite-list-head"><strong>已创建的邀请</strong><span>点击复制后可直接发送给成员</span></div>'
            + '    <div class="sp-invite-list" data-invite-list></div>'
            + '  </div>'
            + '  <footer class="sp-kc-foot"><span></span><button type="button" class="sp-kc-cancel">关闭</button></footer>'
            + '</div>';
        document.body.appendChild(inviteEditor);

        var channel = inviteEditor.querySelector('.sp-invite-channel');
        var duration = inviteEditor.querySelector('.sp-invite-duration');
        var times = inviteEditor.querySelector('.sp-invite-times');
        var list = inviteEditor.querySelector('[data-invite-list]');
        var status = inviteEditor.querySelector('[data-invite-status]');
        setupChannelPicker(channel);

        function request(action, extra) {
            var data = new FormData();
            data.append('_', cfg.inviteToken);
            data.append('action', action);
            data.append('guild_id', ((field('guildId') || {}).value || '').trim());
            data.append('channel_id', channel.value.trim());
            Object.keys(extra || {}).forEach(function (key) { data.append(key, extra[key]); });
            return fetch(cfg.inviteEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.error) || '操作失败');
                    return payload;
                });
        }

        function load() {
            list.textContent = '读取中…';
            request('list').then(function (payload) {
                list.innerHTML = '';
                (payload.items || []).forEach(function (item) {
                    var row = document.createElement('div');
                    row.className = 'sp-invite-row';
                    var code = document.createElement('code');
                    code.textContent = item.url || item.url_code || '';
                    var copy = document.createElement('button');
                    copy.type = 'button';
                    copy.textContent = '复制';
                    copy.addEventListener('click', function () {
                        if (navigator.clipboard) navigator.clipboard.writeText(code.textContent);
                        copy.textContent = '已复制';
                    });
                    var del = document.createElement('button');
                    del.type = 'button';
                    del.textContent = '删除';
                    del.addEventListener('click', function () {
                        request('delete', { url_code: item.url_code || '' }).then(load).catch(function (error) { status.textContent = error.message; });
                    });
                    row.appendChild(code);
                    row.appendChild(copy);
                    row.appendChild(del);
                    list.appendChild(row);
                });
                if (!list.children.length) list.textContent = '还没有邀请链接';
            }).catch(function (error) { list.textContent = error.message || '读取失败'; });
        }

        inviteEditor.querySelector('.sp-invite-create').addEventListener('click', function () {
            status.textContent = '创建中…';
            request('create', { duration: duration.value, times: times.value }).then(function (payload) {
                status.textContent = payload.url || '已创建';
                load();
            }).catch(function (error) { status.textContent = error.message || '创建失败'; });
        });
        inviteEditor.querySelector('.sp-kc-close').addEventListener('click', closeInviteEditor);
        inviteEditor.querySelector('.sp-kc-cancel').addEventListener('click', closeInviteEditor);
        inviteEditor.addEventListener('click', function (event) { if (event.target === inviteEditor) closeInviteEditor(); });
        document.addEventListener('keydown', function (event) { if (!inviteEditor.hidden && 'Escape' === event.key) closeInviteEditor(); });
        inviteEditor.__open = function () {
            inviteEditor.hidden = false;
            document.body.classList.add('sp-kc-open');
            load();
        };

        return inviteEditor;
    }

    function openInviteEditor() {
        ensureInviteEditor().__open();
    }

    function closeInviteEditor() {
        if (!inviteEditor) return;
        inviteEditor.hidden = true;
        document.body.classList.remove('sp-kc-open');
    }

    var postEditor = null;

    function ensurePostEditor() {
        if (postEditor) return postEditor;

        postEditor = document.createElement('div');
        postEditor.className = 'sp-kc-overlay sp-post-overlay';
        postEditor.hidden = true;
        postEditor.innerHTML = ''
            + '<div class="sp-kc-window sp-post-window">'
            + '  <header class="sp-kc-head"><div><span>ARTICLE PUSH</span><h3>文章推送设置</h3></div><button type="button" class="sp-kc-close" aria-label="关闭">×</button></header>'
            + '  <div class="sp-post-main">'
            + '    <div class="sp-post-form">'
            + '      <label class="sp-kc-label">推送频道</label>'
            + '      <div class="sp-picker-field"><button type="button" class="sp-picker-display">选择文章推送频道</button><input type="hidden" class="sp-post-channel"><div class="sp-picker-dropdown" hidden></div></div>'
            + '      <label class="sp-kc-label">卡片标题</label>'
            + '      <input type="text" class="sp-post-title" placeholder="新文章 · {title}">'
            + '      <label class="sp-kc-label">卡片正文 / KMarkdown</label>'
            + '      <div class="sp-kc-toolbar"><button type="button" data-pwrap="**|**|粗体">B</button><button type="button" data-pwrap="*|*|斜体"><i>I</i></button><button type="button" data-pwrap="__|__|下划线"><u>U</u></button><button type="button" data-pwrap="~~|~~|删除线"><s>S</s></button><button type="button" data-pwrap="`|`|代码">&lt;/&gt;</button><button type="button" data-pwrap="```|```|代码块">▤</button><button type="button" data-pwrap="[|](https://)|链接">↗</button><button type="button" data-pinsert="&gt; |引用">❝</button><button type="button" data-pinsert="(spl) |(spl)">◐</button><button type="button" data-pinsert="- |列表">•</button><button type="button" data-pinsert="\n---\n">—</button><button type="button" data-pvar="{title}">{title}</button><button type="button" data-pvar="{summary}">{summary}</button><button type="button" data-pvar="{url}">{url}</button><button type="button" data-pvar="{site}">{site}</button><button type="button" data-pvar="{author}">{author}</button><button type="button" data-pvar="{time}">{time}</button></div>'
            + '      <textarea class="sp-post-body" rows="6" placeholder="{summary}&#10;&#10;[阅读全文]({url})"></textarea>'
            + '      <p class="sp-kc-help">变量：<code>{title}</code> <code>{summary}</code> <code>{url}</code> <code>{site}</code> <code>{author}</code> <code>{time}</code></p>'
            + '    </div>'
            + '    <div class="sp-post-preview-pane"><span class="sp-kc-preview-label">LIVE PREVIEW</span><div class="sp-post-preview"><span>KOOK · TYPE 10</span><h3 data-post-preview-title>新文章 · 示例标题</h3><div data-post-preview-body></div><footer>新文章发布后自动发送 · #FFCDCC</footer></div></div>'
            + '  </div>'
            + '  <footer class="sp-kc-foot"><span></span><button type="button" class="sp-kc-cancel">取消</button><button type="button" class="sp-kc-apply">应用文章推送</button></footer>'
            + '</div>';
        document.body.appendChild(postEditor);

        var channel = postEditor.querySelector('.sp-post-channel');
        var title = postEditor.querySelector('.sp-post-title');
        var body = postEditor.querySelector('.sp-post-body');
        var previewTitle = postEditor.querySelector('[data-post-preview-title]');
        var previewBody = postEditor.querySelector('[data-post-preview-body]');
        setupChannelPicker(channel);

        function sample(template) {
            return String(template || '')
                .replace(/\{title\}/g, '示例文章标题')
                .replace(/\{summary\}/g, '这里是文章摘要，发布后自动替换为真实内容。')
                .replace(/\{url\}/g, 'https://example.com/post/1')
                .replace(/\{site\}/g, cfg.siteTitle || '站点')
                .replace(/\{author\}/g, 'Ayko')
                .replace(/\{time\}/g, new Date().toLocaleString('zh-CN', { hour12: false }));
        }

        function update() {
            previewTitle.textContent = sample(title.value) || '新文章 · 示例标题';
            previewBody.innerHTML = renderKMarkdown(sample(body.value));
        }

        [title, body].forEach(function (input) { input.addEventListener('input', update); });
        postEditor.querySelectorAll('[data-pwrap]').forEach(function (button) {
            button.addEventListener('click', function () {
                var parts = button.getAttribute('data-pwrap').split('|');
                var start = body.selectionStart;
                var end = body.selectionEnd;
                var selected = body.value.slice(start, end) || (parts[2] || '文字');
                body.setRangeText(parts[0] + selected + parts[1], start, end, 'end');
                body.focus();
                update();
            });
        });
        postEditor.querySelectorAll('[data-pinsert]').forEach(function (button) {
            button.addEventListener('click', function () {
                body.setRangeText(button.getAttribute('data-pinsert'), body.selectionStart, body.selectionEnd, 'end');
                body.focus();
                update();
            });
        });
        postEditor.querySelectorAll('[data-pvar]').forEach(function (button) {
            button.addEventListener('click', function () {
                body.setRangeText(button.getAttribute('data-pvar'), body.selectionStart, body.selectionEnd, 'end');
                body.focus();
                update();
            });
        });
        postEditor.querySelector('.sp-kc-close').addEventListener('click', closePostEditor);
        postEditor.querySelector('.sp-kc-cancel').addEventListener('click', closePostEditor);
        postEditor.addEventListener('click', function (event) { if (event.target === postEditor) closePostEditor(); });
        document.addEventListener('keydown', function (event) { if (!postEditor.hidden && 'Escape' === event.key) closePostEditor(); });
        postEditor.querySelector('.sp-kc-apply').addEventListener('click', function () {
            setFieldValue('postChannelId', channel.value.trim());
            setFieldValue('postCardTitle', title.value.trim());
            setFieldValue('postCardBody', body.value);
            closePostEditor();
        });

        postEditor.__open = function () {
            channel.value = ((field('postChannelId') || {}).value || '').trim();
            if (channel.__syncPicker) channel.__syncPicker();
            title.value = ((field('postCardTitle') || {}).value || '新文章 · {title}').trim();
            body.value = ((field('postCardBody') || {}).value || '').trim();
            update();
            postEditor.hidden = false;
            document.body.classList.add('sp-kc-open');
            title.focus();
        };

        return postEditor;
    }

    function openPostEditor() {
        ensurePostEditor().__open();
    }

    function closePostEditor() {
        if (!postEditor) return;
        postEditor.hidden = true;
        document.body.classList.remove('sp-kc-open');
    }

    var githubEditor = null;

    function ensureGithubEditor() {
        if (githubEditor) return githubEditor;

        githubEditor = document.createElement('div');
        githubEditor.className = 'sp-kc-overlay sp-github-overlay';
        githubEditor.hidden = true;
        githubEditor.innerHTML = ''
            + '<div class="sp-kc-window sp-github-window">'
            + '  <header class="sp-kc-head"><div><span>GITHUB PUSH</span><h3>GitHub 推送设置</h3></div><button type="button" class="sp-kc-close" aria-label="关闭">×</button></header>'
            + '  <div class="sp-github-main">'
            + '    <div class="sp-github-form">'
            + '      <label class="sp-kc-label">Webhook Secret</label>'
            + '      <input type="password" class="sp-github-secret" autocomplete="off" placeholder="与 GitHub Webhook 的 Secret 一致">'
            + '      <label class="sp-kc-label">通知频道</label>'
            + '      <div class="sp-picker-field"><button type="button" class="sp-picker-display">留空则使用图片上传频道</button><input type="hidden" class="sp-github-channel"><div class="sp-picker-dropdown" hidden></div></div>'
            + '      <label class="sp-kc-label">卡片附加说明 / KMarkdown</label><div class="sp-kc-toolbar"><button type="button" data-gwrap="**|**|粗体">B</button><button type="button" data-gwrap="*|*|斜体"><i>I</i></button><button type="button" data-gwrap="__|__|下划线"><u>U</u></button><button type="button" data-gwrap="~~|~~|删除线"><s>S</s></button><button type="button" data-gwrap="`|`|代码">&lt;/&gt;</button><button type="button" data-gwrap="[|](https://)|链接">↗</button><button type="button" data-ginsert="&gt; |引用">❝</button><button type="button" data-ginsert="(spl) |(spl)">◐</button><button type="button" data-ginsert="\n---\n">—</button></div><textarea class="sp-github-note" rows="3" placeholder="留空时使用仓库描述"></textarea>'
            + '      <label class="sp-kc-label">Webhook URL</label>'
            + '      <div class="sp-github-webhook"><code></code><button type="button">复制</button></div>'
            + '      <p class="sp-kc-help">GitHub 仓库 → Settings → Webhooks → Add webhook；Content type 选择 application/json，事件选择 Just the push event。</p>'
            + '    </div>'
            + '    <div class="sp-github-preview-pane"><div class="sp-sim-bar"><span class="sp-kc-preview-label">CARD PREVIEW</span><div class="sp-sim-themes"><button type="button" data-sim-theme="light" class="is-active">浅色</button><button type="button" data-sim-theme="dark">深色</button></div></div>'
            + '      <div class="sp-kook-sim" data-sim-theme="light"><div class="sp-sim-message"><span class="sp-sim-avatar">宅</span><div class="sp-sim-content"><header><strong>宅の家</strong><i>机器人</i><span>今天 06:56</span></header><div class="sp-github-preview"><span>GITHUB · PUSH</span><strong>owner/repository</strong><p><b>推送人员：</b>Ayko</p><p><b>分支信息：</b>main</p><div class="sp-github-commit"><i>fix</i><span>更新 GitHub 推送卡片样式</span><small>a1b2c3d</small></div><div class="sp-github-commit"><i>feat</i><span>应用设置可视化重构</span><small>d4e5f6a</small></div><footer data-github-preview-note>GitHub · Push</footer></div></div></div></div></div>'
            + '  </div>'
            + '  <footer class="sp-kc-foot"><span></span><button type="button" class="sp-kc-cancel">取消</button><button type="button" class="sp-kc-apply">应用 GitHub 设置</button></footer>'
            + '</div>';

        document.body.appendChild(githubEditor);

        var secret = githubEditor.querySelector('.sp-github-secret');
        var channel = githubEditor.querySelector('.sp-github-channel');
        var note = githubEditor.querySelector('.sp-github-note');
        var webhook = githubEditor.querySelector('.sp-github-webhook code');
        var copy = githubEditor.querySelector('.sp-github-webhook button');
        setupChannelPicker(channel);
        note.addEventListener('input', function () {
            var preview = githubEditor.querySelector('[data-github-preview-note]');
            preview.innerHTML = note.value.trim() ? renderKMarkdown(note.value) : 'GitHub · Push';
        });
        githubEditor.querySelectorAll('[data-gwrap]').forEach(function (button) {
            button.addEventListener('click', function () {
                var parts = button.getAttribute('data-gwrap').split('|');
                var start = note.selectionStart;
                var end = note.selectionEnd;
                var selected = note.value.slice(start, end) || (parts[2] || '文字');
                note.setRangeText(parts[0] + selected + parts[1], start, end, 'end');
                note.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
        githubEditor.querySelectorAll('[data-ginsert]').forEach(function (button) {
            button.addEventListener('click', function () {
                note.setRangeText(button.getAttribute('data-ginsert'), note.selectionStart, note.selectionEnd, 'end');
                note.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
        githubEditor.querySelectorAll('[data-sim-theme]').forEach(function (button) {
            button.addEventListener('click', function () {
                var theme = button.getAttribute('data-sim-theme');
                githubEditor.querySelector('.sp-kook-sim').setAttribute('data-sim-theme', theme);
                githubEditor.querySelectorAll('[data-sim-theme]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
            });
        });

        if (cfg.webhookUrl) {
            webhook.textContent = cfg.webhookUrl;
        } else {
            webhook.textContent = '未生成 Webhook URL';
        }

        copy.addEventListener('click', function () {
            if (!cfg.webhookUrl) return;
            if (navigator.clipboard) navigator.clipboard.writeText(cfg.webhookUrl);
            copy.textContent = '已复制';
            window.setTimeout(function () { copy.textContent = '复制'; }, 1500);
        });

        githubEditor.querySelector('.sp-kc-close').addEventListener('click', closeGithubEditor);
        githubEditor.querySelector('.sp-kc-cancel').addEventListener('click', closeGithubEditor);
        githubEditor.addEventListener('click', function (event) { if (event.target === githubEditor) closeGithubEditor(); });
        document.addEventListener('keydown', function (event) { if (!githubEditor.hidden && 'Escape' === event.key) closeGithubEditor(); });
        githubEditor.querySelector('.sp-kc-apply').addEventListener('click', function () {
            setFieldValue('githubSecret', secret.value.trim());
            setFieldValue('githubChannelId', channel.value.trim());
            setFieldValue('githubCardNote', note.value);
            closeGithubEditor();
        });

        githubEditor.__open = function () {
            secret.value = ((field('githubSecret') || {}).value || '').trim();
            channel.value = ((field('githubChannelId') || {}).value || '').trim();
            note.value = ((field('githubCardNote') || {}).value || '').trim();
            if (channel.__syncPicker) channel.__syncPicker();
            githubEditor.hidden = false;
            document.body.classList.add('sp-kc-open');
            secret.focus();
        };

        return githubEditor;
    }

    function openGithubEditor() {
        ensureGithubEditor().__open();
    }

    function closeGithubEditor() {
        if (!githubEditor) return;
        githubEditor.hidden = true;
        document.body.classList.remove('sp-kc-open');
    }

    function setupUserPicker(input) {
        if (!input || input.dataset.spUserPicker) return;

        input.dataset.spUserPicker = '1';
        var wrapper = document.createElement('div');
        wrapper.className = 'sp-picker-field sp-user-picker';
        var display = document.createElement('button');
        display.type = 'button';
        display.className = 'sp-picker-display';
        display.textContent = '选择 KOOK 用户';
        var dropdown = document.createElement('div');
        dropdown.className = 'sp-picker-dropdown';
        dropdown.hidden = true;
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        input.type = 'hidden';
        wrapper.insertBefore(display, input);
        wrapper.appendChild(dropdown);

        function userById(id) {
            if (!directoryData || !directoryData.users) return null;
            return directoryData.users.filter(function (user) { return user.id === id; })[0] || null;
        }

        function sync() {
            var id = input.value.trim();
            var user = userById(id);
            display.textContent = id ? (user ? user.name : id) : '选择 KOOK 用户';
            display.classList.toggle('is-empty', !id);
            display.title = id && user ? user.name + ' · ' + user.id : '选择 KOOK 用户';
        }

        function render(query) {
            query = (query || '').trim().toLowerCase();
            var users = (directoryData && directoryData.users ? directoryData.users : []).filter(function (user) {
                return !query || user.id.indexOf(query) !== -1 || user.name.toLowerCase().indexOf(query) !== -1;
            }).slice(0, 30);
            dropdown.innerHTML = '';

            var search = document.createElement('input');
            search.className = 'sp-role-search';
            search.placeholder = '搜索成员';
            search.autocomplete = 'off';
            dropdown.appendChild(search);

            if (!users.length) {
                var empty = document.createElement('span');
                empty.className = 'sp-picker-empty';
                empty.textContent = directoryData ? '没有匹配的成员' : '请先读取服务器目录';
                dropdown.appendChild(empty);
            }

            users.forEach(function (user) {
                var option = document.createElement('button');
                option.type = 'button';
                option.className = 'sp-picker-option' + (user.id === input.value ? ' is-selected' : '');
                option.innerHTML = '<div><strong></strong><small></small></div>';
                option.querySelector('strong').textContent = user.name;
                option.querySelector('small').textContent = user.id;
                option.addEventListener('click', function () {
                    input.value = user.id;
                    sync();
                    dropdown.hidden = true;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
                dropdown.appendChild(option);
            });

            search.addEventListener('input', function () { render(search.value); });
            window.setTimeout(function () { search.focus(); }, 30);
        }

        display.addEventListener('click', function () {
            if (dropdown.hidden) {
                render('');
                dropdown.hidden = false;
            } else {
                dropdown.hidden = true;
            }
        });
        document.addEventListener('sp-directory-ready', sync);
        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) dropdown.hidden = true;
        });
        input.__syncPicker = sync;
        sync();
    }

    function setupChannelPicker(input) {
        if (!input || input.dataset.spChannelPicker) return;

        input.dataset.spChannelPicker = '1';
        var wrapper = input.closest('.sp-picker-field');
        var display = wrapper ? wrapper.querySelector('.sp-picker-display') : null;
        var dropdown = wrapper ? wrapper.querySelector('.sp-picker-dropdown') : null;

        if (!dropdown || !display) return;

        var placeholder = display.textContent.trim();

        function channelById(id) {
            if (!directoryData || !directoryData.channels) return null;
            return directoryData.channels.filter(function (channel) { return channel.id === id; })[0] || null;
        }

        function sync() {
            var id = input.value.trim();
            var channel = channelById(id);
            display.textContent = id ? (channel ? channel.name : id) : placeholder;
            display.classList.toggle('is-empty', !id);
            display.title = id && channel ? channel.name + ' · ' + id : placeholder;
        }

        function render() {
            var channels = (directoryData && directoryData.channels ? directoryData.channels : []).filter(function (channel) {
                return !channel.isCategory;
            }).slice(0, 50);
            dropdown.innerHTML = '';

            if (!channels.length) {
                var empty = document.createElement('span');
                empty.className = 'sp-picker-empty';
                empty.textContent = directoryData ? '没有可用频道' : '请先在应用设置中读取服务器';
                dropdown.appendChild(empty);
                return;
            }

            channels.forEach(function (channel) {
                var option = document.createElement('button');
                option.type = 'button';
                option.className = 'sp-picker-option' + (channel.id === input.value ? ' is-selected' : '');
                option.innerHTML = '<div><strong></strong><small></small></div>';
                option.querySelector('strong').textContent = channel.name;
                option.querySelector('small').textContent = channel.id;
                option.addEventListener('click', function () {
                    input.value = channel.id;
                    sync();
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    dropdown.hidden = true;
                });
                dropdown.appendChild(option);
            });
        }

        function open() {
            sync();
            render();
            dropdown.hidden = false;
        }

        display.addEventListener('click', function () {
            if (dropdown.hidden) open(); else dropdown.hidden = true;
        });
        input.addEventListener('change', sync);
        document.addEventListener('sp-directory-ready', sync);
        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) dropdown.hidden = true;
        });
        input.__syncPicker = sync;

        sync();
    }

    function setupRolePicker(row) {
        var field = row.querySelector('.sp-role-field');
        var input = row.querySelector('[data-role-id]');
        var name = row.querySelector('[data-role-name]');
        var display = row.querySelector('.sp-role-display');
        var dropdown = row.querySelector('.sp-role-dropdown');
        var placeholder = display.textContent.trim();

        function roleById(id) {
            if (!directoryData || !directoryData.roles) return null;
            return directoryData.roles.filter(function (role) { return role.id === id; })[0] || null;
        }

        function sync() {
            var id = input.value.trim();
            var role = roleById(id);
            var label = name.value.trim() || (role ? role.name : '') || (id ? '角色 ' + id : '');
            display.textContent = label || placeholder;
            display.classList.toggle('is-empty', !label);
            display.title = id && role ? role.name + ' · ' + role.id : (label || placeholder);
        }

        function render(query) {
            query = (query || '').trim().toLowerCase();
            var roles = (directoryData && directoryData.roles ? directoryData.roles : []).filter(function (role) {
                return !query || role.id.indexOf(query) !== -1 || role.name.toLowerCase().indexOf(query) !== -1;
            }).slice(0, 30);
            dropdown.innerHTML = '';

            var search = document.createElement('input');
            search.className = 'sp-role-search';
            search.placeholder = '搜索身份组';
            search.autocomplete = 'off';
            dropdown.appendChild(search);

            if (!roles.length) {
                var empty = document.createElement('span');
                empty.className = 'sp-role-empty';
                empty.textContent = directoryData ? '没有匹配的身份组' : '请先在应用设置中读取服务器';
                dropdown.appendChild(empty);
            }

            roles.forEach(function (role) {
                var option = document.createElement('button');
                option.type = 'button';
                option.className = 'sp-role-option' + (role.id === input.value ? ' is-selected' : '');
                option.innerHTML = '<strong></strong><small></small>';
                option.querySelector('strong').textContent = role.name;
                option.querySelector('small').textContent = role.id;
                option.addEventListener('click', function () {
                    input.value = role.id;
                    name.value = role.name;
                    sync();
                    dropdown.hidden = true;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
                dropdown.appendChild(option);
            });

            search.addEventListener('input', function () { render(search.value); });
            window.setTimeout(function () { search.focus(); }, 30);
        }

        function open() {
            sync();
            render('');
            dropdown.hidden = false;
        }

        display.addEventListener('click', function () {
            if (dropdown.hidden) open(); else dropdown.hidden = true;
        });
        name.addEventListener('input', sync);
        document.addEventListener('sp-directory-ready', sync);
        document.addEventListener('click', function (event) {
            if (!field.contains(event.target)) dropdown.hidden = true;
        });

        sync();
    }

    var claimEditor = null;

    function parseClaimRoleRows() {
        return (((field('claimRoles') || {}).value || '').split(/\r?\n/)).map(function (line) {
            var parts = line.split('|').map(function (part) { return part.trim(); });
            return parts.length >= 2 && /^\d{2,32}$/.test(parts[0])
                ? { id: parts[0], name: parts[1], theme: parts[2] || 'primary' }
                : null;
        }).filter(Boolean);
    }

    function ensureClaimEditor() {
        if (claimEditor) return claimEditor;

        claimEditor = document.createElement('div');
        claimEditor.className = 'sp-kc-overlay sp-claim-overlay';
        claimEditor.hidden = true;
        claimEditor.innerHTML = ''
            + '<div class="sp-kc-window sp-claim-window">'
            + '  <header class="sp-kc-head"><div><span>ROLE CLAIM EDITOR</span><h3>编辑身份组领取</h3></div><button type="button" class="sp-kc-close" aria-label="关闭">×</button></header>'
            + '  <div class="sp-claim-main">'
            + '    <div class="sp-claim-form">'
            + '      <label class="sp-kc-label">领取频道</label>'
            + '      <div class="sp-picker-field"><button type="button" class="sp-picker-display">选择领取频道</button><input type="hidden" class="sp-claim-channel"><div class="sp-picker-dropdown" hidden></div></div>'
            + '      <div class="sp-claim-list-label"><span>可领取身份组</span><small>选择角色 · 主题色</small></div>'
            + '      <div class="sp-claim-rows"></div>'
            + '      <button type="button" class="sp-claim-add">+ 添加身份组</button>'
            + '      <p class="sp-kc-help">按钮点击由 WebSocket Bot 处理，会直接调用 KOOK 的身份组接口。</p>'
            + '    </div>'
            + '    <div class="sp-claim-preview-pane"><span class="sp-kc-preview-label">LIVE PREVIEW</span><div class="sp-claim-card-preview"><span class="sp-claim-preview-kicker">KOOK · ROLE CLAIM</span><h3>✦ 身份组领取 ✦</h3><p>✦✦✦  <strong>点亮你的专属昵称颜色</strong></p><div class="sp-claim-preview-rows"></div><small>点击角色名领取 · 点击移除取消 · 修改会立即生效</small></div></div>'
            + '  </div>'
            + '  <footer class="sp-kc-foot"><span data-claim-publish-status></span><button type="button" class="sp-kc-cancel">取消</button><button type="button" class="sp-claim-publish">发布到频道</button><button type="button" class="sp-kc-apply">应用身份组</button></footer>'
            + '</div>';

        document.body.appendChild(claimEditor);

        var channel = claimEditor.querySelector('.sp-claim-channel');
        var rows = claimEditor.querySelector('.sp-claim-rows');
        var previewRows = claimEditor.querySelector('.sp-claim-preview-rows');
        setupChannelPicker(channel);

        function addRow(role) {
            role = role || { id: '', name: '', theme: 'primary' };
            var row = document.createElement('div');
            row.className = 'sp-claim-editor-row';
            row.innerHTML = '<div class="sp-role-field"><button type="button" class="sp-role-display">选择身份组</button><input type="hidden" data-role-id><input type="hidden" data-role-name><div class="sp-role-dropdown" hidden></div></div><select data-role-theme><option value="primary">Primary</option><option value="success">Success</option><option value="danger">Danger</option><option value="warning">Warning</option><option value="info">Info</option><option value="secondary">Secondary</option></select><button type="button" data-role-remove aria-label="删除">×</button>';
            row.querySelector('[data-role-id]').value = role.id;
            row.querySelector('[data-role-name]').value = role.name;
            row.querySelector('[data-role-theme]').value = role.theme;
            setupRolePicker(row);
            row.querySelector('[data-role-remove]').addEventListener('click', function () {
                row.remove();
                render();
            });
            var idInput = row.querySelector('[data-role-id]');
            var nameInput = row.querySelector('[data-role-name]');

            idInput.addEventListener('input', function () {
                if (!nameInput.value && directoryData) {
                    var found = directoryData.roles.filter(function (role) { return role.id === idInput.value.trim(); })[0];
                    if (found) nameInput.value = found.name;
                }
                render();
            });
            nameInput.addEventListener('input', render);
            row.querySelector('[data-role-theme]').addEventListener('input', render);
            rows.appendChild(row);
            render();
        }

        function collectRows() {
            return Array.prototype.slice.call(rows.querySelectorAll('.sp-claim-editor-row')).map(function (row) {
                return {
                    id: row.querySelector('[data-role-id]').value.trim(),
                    name: row.querySelector('[data-role-name]').value.trim() || ('角色 ' + row.querySelector('[data-role-id]').value.trim()),
                    theme: row.querySelector('[data-role-theme]').value
                };
            }).filter(function (role) { return /^\d{2,32}$/.test(role.id); });
        }

        function render() {
            var roles = collectRows();
            previewRows.innerHTML = '';

            roles.forEach(function (role) {
                var row = document.createElement('div');
                row.className = 'sp-claim-preview-row';
                var claim = document.createElement('span');
                claim.className = 'sp-claim-preview-role is-' + role.theme;
                claim.textContent = role.name;
                var remove = document.createElement('span');
                remove.className = 'sp-claim-preview-remove';
                remove.textContent = '移除';
                row.appendChild(claim);
                row.appendChild(remove);
                previewRows.appendChild(row);
            });

            if (!roles.length) {
                previewRows.textContent = '还没有身份组';
            }
        }

        claimEditor.querySelector('.sp-claim-add').addEventListener('click', function () { addRow(); });
        claimEditor.querySelector('.sp-kc-close').addEventListener('click', closeClaimEditor);
        claimEditor.querySelector('.sp-kc-cancel').addEventListener('click', closeClaimEditor);
        claimEditor.addEventListener('click', function (event) {
            if (event.target === claimEditor) closeClaimEditor();
        });
        document.addEventListener('keydown', function (event) {
            if (!claimEditor.hidden && 'Escape' === event.key) closeClaimEditor();
        });
        function saveEditorFields() {
            var roles = collectRows();
            var lines = roles.map(function (role) { return role.id + '|' + role.name + '|' + role.theme; });
            setFieldValue('claimChannelId', channel.value.trim());
            setFieldValue('claimRoles', lines.join('\n'));
            return lines.join('\n');
        }

        claimEditor.querySelector('.sp-kc-apply').addEventListener('click', function () {
            saveEditorFields();
            closeClaimEditor();
        });

        claimEditor.querySelector('.sp-claim-publish').addEventListener('click', function () {
            var status = claimEditor.querySelector('[data-claim-publish-status]');
            var lines = saveEditorFields();
            var data = new FormData();
            data.append('_', cfg.claimToken);
            data.append('channel_id', channel.value.trim());
            data.append('roles', lines);
            status.textContent = '发布中…';
            fetch(cfg.claimEndpoint, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) throw new Error((payload && payload.error) || '发布失败');
                    status.textContent = '已发布到频道';
                })
                .catch(function (error) { status.textContent = error.message || '发布失败'; });
        });

        claimEditor.__open = function () {
            channel.value = ((field('claimChannelId') || {}).value || '').trim();
            if (channel.__syncPicker) channel.__syncPicker();
            rows.innerHTML = '';
            var existing = parseClaimRoleRows();

            if (!existing.length) {
                addRow();
            } else {
                existing.forEach(addRow);
            }

            render();
            populateDirectoryDatalists();
            claimEditor.hidden = false;
            document.body.classList.add('sp-kc-open');
            channel.focus();
        };

        return claimEditor;
    }

    function openClaimEditor() {
        ensureClaimEditor().__open();
    }

    function closeClaimEditor() {
        if (!claimEditor) return;
        claimEditor.hidden = true;
        document.body.classList.remove('sp-kc-open');
    }

    function buildClaimPanel(panel) {
        var wrap = document.createElement('div');
        wrap.className = 'sp-ks-claim';
        wrap.innerHTML = '<div class="sp-ks-claim-preview"></div><div class="sp-ks-claim-actions"><button type="button" data-claim-edit>可视化编辑</button><button type="button" data-claim-publish>发布领取卡片</button><span data-claim-status></span></div>';
        panel.querySelector('.sp-ks-fields').appendChild(wrap);

        var preview = wrap.querySelector('.sp-ks-claim-preview');
        var status = wrap.querySelector('[data-claim-status]');

        function renderRoles() {
            var raw = ((field('claimRoles') || {}).value || '');
            var roles = raw.split(/\r?\n/).map(function (line) {
                var parts = line.split('|').map(function (part) { return part.trim(); });
                return parts.length >= 2 ? { id: parts[0], name: parts[1], theme: parts[2] || 'primary' } : null;
            }).filter(Boolean);
            preview.innerHTML = '';

            if (!roles.length) {
                preview.textContent = '还没有配置身份组';
                return;
            }

            roles.forEach(function (role) {
                var row = document.createElement('div');
                row.className = 'sp-ks-claim-row';
                var button = document.createElement('span');
                button.className = 'sp-ks-claim-role is-' + role.theme;
                button.textContent = role.name;
                var remove = document.createElement('span');
                remove.className = 'sp-ks-claim-remove';
                remove.textContent = '移除';
                row.appendChild(button);
                row.appendChild(remove);
                preview.appendChild(row);
            });
        }

        var rolesField = field('claimRoles');
        if (rolesField) rolesField.addEventListener('input', renderRoles);

        wrap.querySelector('[data-claim-edit]').addEventListener('click', openClaimEditor);

        wrap.querySelector('[data-claim-publish]').addEventListener('click', function () {
            if (!cfg.claimEndpoint || !cfg.claimToken) {
                status.textContent = '接口未配置';
                return;
            }

            var data = new FormData();
            data.append('_', cfg.claimToken);
            status.textContent = '发布中…';

            fetch(cfg.claimEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                if (!payload || !payload.ok) {
                    throw new Error((payload && payload.error) || '发布失败');
                }
                status.textContent = '已发布到频道';
            }).catch(function (error) {
                status.textContent = error.message || '发布失败';
            });
        });

        renderRoles();
    }

    function attachLogoUpload(name, label, after) {
        var input = field(name);

        if (!input || !cfg.uploadEndpoint || !cfg.uploadToken) {
            return;
        }

        var option = input.closest('.sp-ks-option');

        if (!option) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'sp-ks-upload';
        wrap.innerHTML = '<button type="button" class="sp-ks-upload-btn">' + label + '</button><span class="sp-ks-upload-status"></span>';
        var file = document.createElement('input');
        file.type = 'file';
        file.accept = 'image/png,image/jpeg,image/gif,image/webp,image/svg+xml';
        file.hidden = true;
        wrap.appendChild(file);
        option.appendChild(wrap);

        var button = wrap.querySelector('.sp-ks-upload-btn');
        var status = wrap.querySelector('.sp-ks-upload-status');

        button.addEventListener('click', function () {
            file.click();
        });

        file.addEventListener('change', function () {
            var selected = file.files && file.files[0];

            if (!selected) {
                return;
            }

            if (selected.size > 4 * 1024 * 1024) {
                status.textContent = '图片不能超过 4MB';
                status.classList.add('is-error');
                return;
            }

            var data = new FormData();
            data.append('file', selected, selected.name);
            data.append('_', cfg.uploadToken);
            status.textContent = '上传到 KOOK…';
            status.classList.remove('is-error', 'is-ok');
            button.disabled = true;

            fetch(cfg.uploadEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            }).then(function (response) {
                return response.json();
            }).then(function (payload) {
                if (!payload || !payload.ok || !payload.url) {
                    throw new Error((payload && payload.error) || '上传失败');
                }

                input.value = payload.url;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                status.textContent = '已发送到 KOOK';
                status.classList.add('is-ok');
                status.classList.remove('is-error');

                if (typeof after === 'function') {
                    after();
                } else {
                    refreshLogo();
                }
            }).catch(function (error) {
                status.textContent = error.message || '上传失败';
                status.classList.add('is-error');
                status.classList.remove('is-ok');
            }).then(function () {
                button.disabled = false;
                file.value = '';
            });
        });
    }

    function enhance() {
        var options = Array.prototype.slice.call(form.querySelectorAll('.typecho-option'));
        var submitOption = options.find(function (option) {
            return !!option.querySelector('[type="submit"]');
        });
        var firstOption = options[0];

        if (!firstOption) {
            return;
        }

        var hero = document.createElement('div');
        hero.className = 'sp-ks-hero';
        hero.innerHTML = ''
            + '<div class="sp-ks-hero-copy"><p class="sp-ks-kicker">MOE PANEL · KOOK</p>'
            + '<h2>登录与品牌设置</h2><p>配置 KOOK 登录、卡片消息、账号映射与后台品牌。修改会自动保存。</p></div>'
            + '<div class="sp-ks-chips"><span class="sp-ks-chip" data-chip="enable"></span>'
            + '<span class="sp-ks-chip" data-chip="oauth"></span><span class="sp-ks-chip" data-chip="code"></span></div>';

        var layout = document.createElement('div');
        layout.className = 'sp-ks-layout';
        var nav = document.createElement('nav');
        nav.className = 'sp-ks-nav';
        nav.setAttribute('aria-label', '设置分类');
        var panels = document.createElement('div');
        panels.className = 'sp-ks-panels';

        layout.appendChild(nav);
        layout.appendChild(panels);
        form.insertBefore(hero, firstOption);
        form.insertBefore(layout, firstOption);

        var buttons = [];
        var sections = [];
        var lastGroup = '';

        sectionMap.forEach(function (section) {
            var matching = options.filter(function (option) {
                return section.fields.indexOf(optionName(option)) !== -1;
            });

            if (!matching.length && !section.always) {
                return;
            }

            if (section.hidden) {
                matching.forEach(function (option) {
                    if ('card' === section.id) option.classList.add('sp-ks-card-source');
                    if ('welcome' === section.id) option.classList.add('sp-ks-welcome-source');
                    if ('claim' === section.id) option.classList.add('sp-ks-claim-source');
                    if ('github' === section.id) option.classList.add('sp-ks-github-source');
                    if ('post' === section.id) option.classList.add('sp-ks-post-source');
                    if ('custom' === section.id) option.classList.add('sp-ks-custom-source');
                    if ('palette' === section.id) option.classList.add('sp-ks-palette-source');
                    if ('ai' === section.id) option.classList.add('sp-ks-ai-source');
                });
                return;
            }

            var panel = document.createElement('section');
            panel.className = 'sp-ks-panel';
            panel.setAttribute('data-panel', section.id);
            panel.innerHTML = '<header class="sp-ks-panel-head"><span>' + String(sections.length + 1).padStart(2, '0')
                + '</span><div><h3></h3><p></p></div></header><div class="sp-ks-fields"></div>';
            panel.querySelector('h3').textContent = section.title;
            panel.querySelector('p').textContent = section.hint;

            var fieldWrap = panel.querySelector('.sp-ks-fields');
            matching.forEach(function (option) {
                option.classList.add('sp-ks-option');

                if ('card' === section.id) {
                    option.classList.add('sp-ks-card-source');
                }

                if ('palette' === section.id) {
                    option.classList.add('sp-ks-palette-source');
                }

                if ('claim' === section.id) {
                    option.classList.add('sp-ks-claim-source');
                }

                fieldWrap.appendChild(option);
            });

            panels.appendChild(panel);
            sections.push(panel);

            if (section.group && section.group !== lastGroup) {
                var currentGroup = section.group;
                var groupLabel = document.createElement('button');
                groupLabel.type = 'button';
                groupLabel.className = 'sp-ks-nav-group';
                groupLabel.setAttribute('data-nav-group-toggle', currentGroup);
                groupLabel.setAttribute('aria-expanded', 'true');
                groupLabel.innerHTML = '<span></span><i aria-hidden="true">⌄</i>';
                groupLabel.querySelector('span').textContent = currentGroup;
                nav.appendChild(groupLabel);
                lastGroup = currentGroup;
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sp-ks-nav-item is-child';
            button.setAttribute('data-target', section.id);
            button.setAttribute('data-nav-group', section.group || '');
            button.innerHTML = '<span>' + String(buttons.length + 1).padStart(2, '0') + '</span><strong></strong><i>→</i>';
            button.querySelector('strong').textContent = section.title;
            nav.appendChild(button);
            buttons.push(button);
        });

        // 这些字段由应用设置中的可视化弹窗维护，绝不能在插件配置页底部
        // 以原始输入框形式再次出现。
        ['postChannelId', 'postCardTitle', 'postCardBody', 'verifyCardJson', 'successCardJson'].forEach(function (name) {
            var input = field(name);
            var option = input ? input.closest('.typecho-option') : null;

            if (option) option.classList.add('sp-ks-custom-source');
        });

        options.forEach(function (option) {
            if (!option.closest('[data-panel]')) {
                option.classList.add('sp-ks-orphan-source');
            }
        });

        nav.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-nav-group-toggle]');

            if (!toggle) return;

            event.preventDefault();
            event.stopPropagation();
            var group = toggle.getAttribute('data-nav-group-toggle');
            var collapsed = 'true' === toggle.getAttribute('aria-expanded');
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.classList.toggle('is-collapsed', collapsed);

            nav.querySelectorAll('[data-nav-group]').forEach(function (item) {
                if (item.getAttribute('data-nav-group') !== group) return;
                item.style.display = collapsed ? 'none' : '';
                item.classList.toggle('is-collapsed', collapsed);
            });
        });

        if (sections.length === 0) {
            return;
        }

        if (submitOption) {
            submitOption.remove();
        }

        var cardPanel = panels.querySelector('[data-panel="card"]');
        if (cardPanel) {
            var cardPreview = buildPreview();
            cardPanel.querySelector('.sp-ks-fields').insertBefore(cardPreview, cardPanel.querySelector('.sp-ks-fields').firstChild);
            cardPreview.querySelectorAll('[data-edit-card]').forEach(function (button) {
                button.addEventListener('click', function () {
                    openEditor(button.getAttribute('data-edit-card'));
                });
            });
        }

        var appearancePanel = panels.querySelector('[data-panel="appearance"]');
        if (appearancePanel) {
            appearancePanel.querySelector('.sp-ks-fields').insertBefore(buildLogoPreview(), appearancePanel.querySelector('.sp-ks-fields').firstChild);
        }

        function activate(id) {
            sections.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-panel') !== id;
            });
            buttons.forEach(function (button) {
                var active = button.getAttribute('data-target') === id;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-current', active ? 'true' : 'false');
            });
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activate(button.getAttribute('data-target'));
            });
        });

        function refreshStatus() {
            var enable = '1' === radioValue('enable');
            var oauth = '1' === radioValue('oauthLogin');
            var code = '1' === radioValue('codeLogin');
            var chips = {
                enable: enable ? 'KOOK 已开启' : 'KOOK 已关闭',
                oauth: oauth ? 'OAuth 开启' : 'OAuth 关闭',
                code: code ? '验证码开启' : '验证码关闭'
            };

            Object.keys(chips).forEach(function (key) {
                var chip = hero.querySelector('[data-chip="' + key + '"]');
                if (!chip) return;
                chip.textContent = chips[key];
                chip.classList.toggle('is-off', '1' !== radioValue('enable') || ('oauth' === key ? !oauth : 'code' === key ? !code : false));
            });
        }

        function refresh() {
            refreshStatus();
            refreshPreview();
            refreshLogo();
            refreshPalette();
        }

        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);

        var claimPanel = panels.querySelector('[data-panel="claim"]');
        if (claimPanel) {
            buildClaimPanel(claimPanel);
        }

        var botPanel = panels.querySelector('[data-panel="bot"]');
        if (botPanel) {
            buildBotPanel(botPanel);
        }

        var updatePanel = panels.querySelector('[data-panel="update"]');
        if (updatePanel) {
            buildUpdatePanel(updatePanel);
        }

        var aiPanel = panels.querySelector('[data-panel="ai"]');
        if (aiPanel) {
            buildAiPanel(aiPanel);
        }

        var aboutPanel = panels.querySelector('[data-panel="about"]');
        if (aboutPanel) {
            buildAboutPanel(aboutPanel);
        }

        var githubPanel = panels.querySelector('[data-panel="github"]');
        if (githubPanel && cfg.webhookUrl) {
            var webhookBox = document.createElement('div');
            webhookBox.className = 'sp-ks-webhook';
            webhookBox.innerHTML = '<span>Webhook URL</span><code></code><button type="button">复制</button>';
            webhookBox.querySelector('code').textContent = cfg.webhookUrl;
            webhookBox.querySelector('button').addEventListener('click', function () {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(cfg.webhookUrl);
                    this.textContent = '已复制';
                    window.setTimeout(function () { webhookBox.querySelector('button').textContent = '复制'; }, 1600);
                }
            });
            githubPanel.querySelector('.sp-ks-fields').appendChild(webhookBox);
        }

        var securityPanel = panels.querySelector('[data-panel="app"]');
        if (securityPanel) {
            var directoryStatus = document.createElement('span');
            directoryStatus.setAttribute('data-directory-status', '');
            directoryStatus.hidden = true;
            securityPanel.querySelector('.sp-ks-fields').appendChild(directoryStatus);

            if (((field('guildId') || {}).value || '').trim()) {
                window.setTimeout(loadDirectory, 250);
            }
        }

        var appPanel = panels.querySelector('[data-panel="app"]');
        if (appPanel) {
            var appActions = document.createElement('div');
            appActions.className = 'sp-ks-app-actions';
            appActions.innerHTML = ''
                + '<div class="sp-app-grid">'
                + '  <div class="sp-app-card"><i>01</i><div class="sp-app-card-copy"><strong>卡片消息</strong><span>验证码与登录成功卡片，支持 KMarkdown 和实时预览。</span></div><div class="sp-app-card-actions"><button type="button" data-open-card="verify">编辑</button></div></div>'
                + '  <div class="sp-app-card"><i>02</i><div class="sp-app-card-copy"><strong>欢迎设置</strong><span>欢迎频道、标题、欢迎语与 16:9 背景卡。</span></div><div class="sp-app-card-actions"><button type="button" data-open-welcome>编辑</button></div></div>'
                + '  <div class="sp-app-card"><i>03</i><div class="sp-app-card-copy"><strong>身份组领取</strong><span>可视化选择角色、主题色与领取按钮。</span></div><div class="sp-app-card-actions"><button type="button" data-open-claim>编辑</button></div></div>'
                + '  <div class="sp-app-card"><i>04</i><div class="sp-app-card-copy"><strong>GitHub 推送</strong><span>Webhook Secret、通知频道与推送卡片。</span></div><div class="sp-app-card-actions"><button type="button" data-open-github>编辑</button></div></div>'
                + '  <div class="sp-app-card"><i>05</i><div class="sp-app-card-copy"><strong>文章推送</strong><span>新文章发布后自动同步 KOOK 的频道与卡片文案。</span></div><div class="sp-app-card-actions"><button type="button" data-open-post>编辑</button></div></div>'
                + '  <div class="sp-app-card"><i>06</i><div class="sp-app-card-copy"><strong>角色扮演</strong><span>接入 DeepSeek，让机器人在被 @ 时进行角色扮演对话。</span></div><div class="sp-app-card-actions"><button type="button" data-open-ai>编辑</button></div></div>'
                + '  <div class="sp-app-card"><i>07</i><div class="sp-app-card-copy"><strong>邀请管理</strong><span>创建、复制与删除 KOOK 服务器邀请链接。</span></div><div class="sp-app-card-actions"><button type="button" data-open-invite>打开</button></div></div>'
                + '  <div class="sp-app-card"><i>08</i><div class="sp-app-card-copy"><strong>在玩状态</strong><span>展示正在玩的游戏或正在听的歌。</span></div><div class="sp-app-card-actions"><button type="button" data-open-presence>打开</button></div></div>'
                + '</div>';
            appPanel.querySelector('.sp-ks-fields').insertBefore(appActions, appPanel.querySelector('.sp-ks-fields').firstChild);
            appActions.querySelectorAll('[data-open-card]').forEach(function (button) {
                button.addEventListener('click', function () {
                    openEditor(button.getAttribute('data-open-card'));
                });
            });
            appActions.querySelector('[data-open-welcome]').addEventListener('click', openWelcomeEditor);
            appActions.querySelector('[data-open-claim]').addEventListener('click', openClaimEditor);
            appActions.querySelector('[data-open-github]').addEventListener('click', openGithubEditor);
            appActions.querySelector('[data-open-post]').addEventListener('click', openPostEditor);
            appActions.querySelector('[data-open-ai]').addEventListener('click', openAiEditor);
            appActions.querySelector('[data-open-invite]').addEventListener('click', openInviteEditor);
            appActions.querySelector('[data-open-presence]').addEventListener('click', openPresenceEditor);

            var ownerPicker = field('ownerKookId');
            if (ownerPicker) setupUserPicker(ownerPicker);

            [
                ['应用凭据', 'clientId'],
                ['账号映射', 'ownerKookId'],
                ['验证码安全', 'codeTtl']
            ].forEach(function (item) {
                var input = field(item[1]);
                var option = input ? input.closest('.sp-ks-option') : null;

                if (!option) return;

                var label = document.createElement('div');
                label.className = 'sp-ks-subgroup';
                label.textContent = item[0];
                option.parentNode.insertBefore(label, option);
            });
        }

        attachPaletteEditor();
        attachLogoUpload('logoUrl', '上传 LOGO');

        activate('login');
        refresh();

        // Keep the active tab after the browser restores an anchor.
        window.addEventListener('hashchange', function () {
            var id = window.location.hash.replace('#sp-ks-', '');
            if (panels.querySelector('[data-panel="' + id + '"]')) {
                activate(id);
            }
        });
    }

    enhance();

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);

    // 标准表单提交也走同一 AJAX 保存，避免整页刷新丢失数据。
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        window.clearTimeout(timer);
        requestSave(true);
    });

    window.addEventListener('beforeunload', function (event) {
        if ((dirty || saving) && !event.defaultPrevented) {
            event.preventDefault();
            event.returnValue = '';
            return '';
        }

        return undefined;
    });
})();
