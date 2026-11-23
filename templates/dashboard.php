<main class="main">
    <div class="container sp-dashboard">
        <header class="sp-dash-head">
            <div>
                <span class="sp-dash-kicker">WORKSPACE / OVERVIEW</span>
                <h1>概览</h1>
            </div>
            <p>站点内容、评论与 KOOK 接入状态，一页查看。</p>
        </header>

        <section class="sp-hero">
            <div class="sp-hero-glow sp-hero-glow--one" aria-hidden="true"></div>
            <div class="sp-hero-glow sp-hero-glow--two" aria-hidden="true"></div>

            <div class="sp-hero-copy">
                <p class="sp-eyebrow">
                    <span class="sp-eyebrow-dot" aria-hidden="true"></span>
                    概览 / Overview
                    <span class="sp-eyebrow-line" aria-hidden="true"></span>
                    <?php echo $e($date); ?> · 星期<?php echo $e($weekday); ?>
                </p>
                <h1><?php echo $e($greeting); ?>，<span><?php echo $e($displayName); ?></span></h1>
                <p class="sp-hero-sub">这里是你创作空间的概览。写下新的想法，或者看看最近发生了什么。</p>
                <div class="sp-hero-actions">
                    <?php if ($canWrite): ?>
                        <a class="sp-btn sp-btn--primary" href="<?php echo $e($url('write-post.php')); ?>">
                            <?php echo $icon('pen'); ?>撰写新文章
                        </a>
                    <?php endif; ?>
                    <a class="sp-btn" href="<?php echo $e($url('manage-comments.php')); ?>">
                        <?php echo $icon('message'); ?>管理评论
                    </a>
                    <a class="sp-btn sp-btn--ghost" href="<?php echo $e((string) $options->siteUrl); ?>"
                       target="_blank" rel="noopener noreferrer">查看站点 ↗</a>
                </div>
            </div>

        </section>

        <?php
        $kookReady = !empty($kook['configured']);
        $kookReachable = !empty($kook['reachable']);
        $kookOnline = $kookReady && !empty($kook['online']);
        $botName = trim((string) ($kook['bot']['username'] ?? $kook['bot']['nickname'] ?? ''));
        $botName = '' !== $botName ? $botName : ($kookReady ? 'KOOK Bot' : 'KOOK Bot 未配置');
        $botAvatar = trim((string) ($kook['bot']['avatar'] ?? ''));
        $botId = trim((string) ($kook['bot']['id'] ?? ''));
        $botId = '' !== $botId ? $botId : ($kookReady ? '1684448971' : '');
        $kookState = !$kookReady ? '未配置' : ($kookOnline ? '运行中' : ($kookReachable ? '离线' : '未连接'));
        $kookStatusClass = !$kookReady ? ' is-idle' : ($kookOnline ? ' is-ready' : ($kookReachable ? ' is-offline' : ' is-unreachable'));
        $kookDetail = !$kookReady
            ? '前往设置填写机器人 Token 与服务器'
            : ($kookOnline ? '机器人运行中 · 已连接 KOOK' : ($kookReachable ? '机器人当前离线 · 需要启动 WebSocket Bot' : '机器人凭据已配置，KOOK 暂无响应'));
        ?>
        <section class="sp-kook-connection<?php echo $kookStatusClass; ?>">
            <div class="sp-kook-connection-avatar">
                <?php if ('' !== $botAvatar): ?>
                    <img src="<?php echo $e($botAvatar); ?>" alt="" referrerpolicy="no-referrer">
                <?php else: ?>
                    <span><?php echo $e(mb_substr($botName, 0, 1, 'UTF-8')); ?></span>
                <?php endif; ?>
            </div>
            <div class="sp-kook-connection-copy">
                <span class="sp-kook-connection-kicker"><i></i> KOOK BOT / CONNECTION</span>
                <h2><?php echo $e($botName); ?></h2>
                <p>
                    <?php if ('' !== $botId): ?>Bot ID <?php echo $e($botId); ?> · <?php endif; ?>
                    <?php echo $e($kookDetail); ?>
                </p>
            </div>
            <div class="sp-kook-connection-side">
                <span class="sp-kook-connection-status">
                    <i></i><?php echo $e($kookState); ?>
                </span>
                <?php if (!$kookReady): ?>
                    <a href="<?php echo $e($kook['settingsUrl']); ?>">KOOK 设置 <span>→</span></a>
                <?php endif; ?>
            </div>
        </section>

        <section class="sp-stats" aria-label="站点数据">
            <a class="sp-stat" href="<?php echo $e($url('manage-posts.php')); ?>">
                <div class="sp-stat-head">
                    <span class="sp-stat-icon sp-stat-icon--indigo"><?php echo $icon('file'); ?></span>
                    <span class="sp-stat-label">文章</span>
                </div>
                <strong data-sp-count="<?php echo $publishedPosts; ?>"><?php echo $publishedPosts; ?></strong>
                <div class="sp-stat-foot">
                    <span>已发布</span>
                    <?php if ($draftPosts > 0): ?><span class="sp-stat-extra"><?php echo $draftPosts; ?> 草稿</span><?php endif; ?>
                </div>
            </a>

            <a class="sp-stat" href="<?php echo $e($url('manage-comments.php')); ?>">
                <div class="sp-stat-head">
                    <span class="sp-stat-icon sp-stat-icon--blue"><?php echo $icon('message'); ?></span>
                    <span class="sp-stat-label">评论</span>
                </div>
                <strong data-sp-count="<?php echo $publishedComments; ?>"><?php echo $publishedComments; ?></strong>
                <div class="sp-stat-foot"><span>已通过</span><span class="sp-stat-extra">全部互动</span></div>
            </a>

            <a class="sp-stat<?php echo $waitingComments > 0 ? ' is-attention' : ''; ?>"
               href="<?php echo $e($url('manage-comments.php?status=waiting')); ?>">
                <div class="sp-stat-head">
                    <span class="sp-stat-icon sp-stat-icon--amber"><?php echo $icon('clock'); ?></span>
                    <span class="sp-stat-label">待审核</span>
                </div>
                <strong data-sp-count="<?php echo $waitingComments; ?>"><?php echo $waitingComments; ?></strong>
                <div class="sp-stat-foot"><span>需要处理</span><span class="sp-stat-extra">评论</span></div>
            </a>

            <a class="sp-stat" href="<?php echo $e($url($isEditor ? 'manage-categories.php' : 'manage-posts.php')); ?>">
                <div class="sp-stat-head">
                    <span class="sp-stat-icon sp-stat-icon--green"><?php echo $icon('folder'); ?></span>
                    <span class="sp-stat-label">内容结构</span>
                </div>
                <strong data-sp-count="<?php echo $categoriesNum; ?>"><?php echo $categoriesNum; ?></strong>
                <div class="sp-stat-foot"><span>分类</span><span class="sp-stat-extra"><?php echo $tagsNum; ?> 标签</span></div>
            </a>
        </section>

        <div class="sp-grid">
            <div class="sp-main">
                <section class="sp-panel">
                    <header class="sp-panel-head">
                        <div>
                            <span class="sp-panel-kicker">Latest writing</span>
                            <h2>最近发布</h2>
                        </div>
                        <a href="<?php echo $e($url('manage-posts.php')); ?>">全部文章 →</a>
                    </header>
                    <ul class="sp-post-list">
                        <?php if ($posts->have()): ?>
                            <?php while ($posts->next()): ?>
                                <li>
                                    <a href="<?php $posts->permalink(); ?>">
                                        <time datetime="<?php $posts->date('c'); ?>"><?php $posts->date('m.d'); ?></time>
                                        <span class="sp-post-title"><?php $posts->title(); ?></span>
                                        <span class="sp-row-arrow" aria-hidden="true">↗</span>
                                    </a>
                                </li>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <li class="sp-empty">还没有文章，写下第一篇吧。</li>
                        <?php endif; ?>
                    </ul>
                </section>

                <section class="sp-panel">
                    <header class="sp-panel-head">
                        <div>
                            <span class="sp-panel-kicker">Recent voices</span>
                            <h2>最近回复</h2>
                        </div>
                        <a href="<?php echo $e($url('manage-comments.php')); ?>">全部评论 →</a>
                    </header>
                    <ul class="sp-comment-list">
                        <?php if ($comments->have()): ?>
                            <?php while ($comments->next()): ?>
                                <li>
                                    <a href="<?php $comments->permalink(); ?>">
                                        <span class="sp-comment-meta">
                                            <span class="sp-comment-author"><?php $comments->author(false); ?></span>
                                            <time datetime="<?php $comments->date('c'); ?>"><?php $comments->date('m.d'); ?></time>
                                        </span>
                                        <p><?php $comments->excerpt(60, '…'); ?></p>
                                    </a>
                                </li>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <li class="sp-empty">还没有回复，安静也很好。</li>
                        <?php endif; ?>
                    </ul>
                </section>
            </div>

            <aside class="sp-rail">
                <section class="sp-panel sp-quick-panel">
                    <header class="sp-panel-head">
                        <div>
                            <span class="sp-panel-kicker">Shortcuts</span>
                            <h2>快捷操作</h2>
                        </div>
                    </header>
                    <div class="sp-quick-list">
                        <?php if ($canWrite): ?>
                            <a class="sp-quick-item" href="<?php echo $e($url('write-post.php')); ?>">
                                <span class="sp-quick-icon"><?php echo $icon('pen'); ?></span>
                                <span>写文章</span>
                                <span class="sp-quick-arrow">→</span>
                            </a>
                        <?php endif; ?>
                        <a class="sp-quick-item" href="<?php echo $e($url('manage-posts.php')); ?>">
                            <span class="sp-quick-icon"><?php echo $icon('file'); ?></span>
                            <span>文章库</span>
                            <span class="sp-quick-arrow">→</span>
                        </a>
                        <a class="sp-quick-item" href="<?php echo $e($url('manage-comments.php')); ?>">
                            <span class="sp-quick-icon"><?php echo $icon('message'); ?></span>
                            <span>评论</span>
                            <span class="sp-quick-arrow">→</span>
                        </a>
                        <?php if ($isEditor): ?>
                            <a class="sp-quick-item" href="<?php echo $e($url('manage-medias.php')); ?>">
                                <span class="sp-quick-icon"><?php echo $icon('image'); ?></span>
                                <span>文件</span>
                                <span class="sp-quick-arrow">→</span>
                            </a>
                            <a class="sp-quick-item" href="<?php echo $e($url('manage-categories.php')); ?>">
                                <span class="sp-quick-icon"><?php echo $icon('folder'); ?></span>
                                <span>分类</span>
                                <span class="sp-quick-arrow">→</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <a class="sp-quick-item" href="<?php echo $e($url('options-general.php')); ?>">
                                <span class="sp-quick-icon"><?php echo $icon('settings'); ?></span>
                                <span>站点设置</span>
                                <span class="sp-quick-arrow">→</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="sp-system">
                    <div class="sp-system-top">
                        <span class="sp-status"><i aria-hidden="true"></i>Setup check</span>
                        <span>v<?php echo $e((string) $options->version); ?></span>
                    </div>
                    <h2>上线检查</h2>
                    <dl class="sp-system-list">
                        <div>
                            <dt>KOOK Bot</dt>
                            <dd class="<?php echo $kookOnline ? 'is-ok' : ($kookReachable ? 'is-warn' : 'is-idle'); ?>">
                                <?php echo $e($kookState); ?>
                            </dd>
                        </div>
                        <div>
                            <dt>图片上传</dt>
                            <dd><?php echo !empty($kook['uploadReady']) ? '已配置' : '待配置'; ?></dd>
                        </div>
                        <div><dt>Typecho</dt><dd><?php echo $e((string) $options->version); ?></dd></div>
                        <div><dt>PHP</dt><dd><?php echo $e(PHP_VERSION); ?></dd></div>
                        <div><dt>Site</dt><dd><a href="<?php echo $e((string) $options->siteUrl); ?>" target="_blank" rel="noopener noreferrer">访问 ↗</a></dd></div>
                    </dl>
                    <div class="sp-system-update">
                        <a href="<?php echo $e((string) ($kook['settingsUrl'] ?? 'options-plugin.php?config=MoePanel')); ?>">检查更新 →</a>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</main>
