<aside class="sp-sidebar" aria-label="后台导航">
    <?php
    $brandLight = (string) ($logoUrl ?? '');
    $brandDark = (string) ($logoDarkUrl ?? '');
    $brandLight = $brandLight !== '' ? $brandLight : $brandDark;
    $brandDark = $brandDark !== '' ? $brandDark : $brandLight;
    ?>
    <a class="sp-brand" href="<?php echo $e($adminUrl); ?>">
        <?php if ($brandLight !== ''): ?>
            <span class="sp-brand-logo sp-brand-logo--image" aria-hidden="true">
                <img class="sp-logo sp-logo--light" src="<?php echo $e($brandLight); ?>" alt="" referrerpolicy="no-referrer">
                <img class="sp-logo sp-logo--dark" src="<?php echo $e($brandDark); ?>" alt="" referrerpolicy="no-referrer">
            </span>
        <?php else: ?>
            <span class="sp-brand-logo" aria-hidden="true"><?php echo $e($initial); ?></span>
        <?php endif; ?>
        <span class="sp-brand-text">
            <strong><?php echo $e($siteTitle); ?></strong>
            <small>MoePanel</small>
        </span>
    </a>

    <button type="button" class="sp-menu-btn" data-sp-menu-toggle aria-expanded="false"
            aria-controls="sp-nav">
        <span class="sp-menu-lines" aria-hidden="true"></span>
        <span class="sr-only">菜单</span>
    </button>

    <div class="sp-nav-scroll">
        <div class="sp-nav-label">
            <span>Workspace</span>
            <button type="button" class="sp-nav-collapse-all" data-sp-nav-collapse
                    aria-label="收起全部分组" title="收起全部分组">
                <?php echo $icon('chevrons'); ?>
            </button>
        </div>
        <div class="sp-nav" id="sp-nav">
            <div class="sp-nav-core"><?php echo $nav; ?></div>
        </div>
    </div>

    <div class="sp-sidebar-footer">
        <a class="sp-user" href="<?php echo $e($profileUrl); ?>">
            <?php if ('' !== (string) ($avatarUrl ?? '')): ?>
                <span class="sp-user-avatar sp-user-avatar--image" aria-hidden="true">
                    <img src="<?php echo $e($avatarUrl); ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                </span>
            <?php else: ?>
                <span class="sp-user-avatar" aria-hidden="true"><?php echo $e($userInitial); ?></span>
            <?php endif; ?>
            <span class="sp-user-info">
                <strong><?php echo $e($userName); ?></strong>
                <small><?php echo $lastLogin !== '' ? $e('最后登录 ' . $lastLogin) : '已登录'; ?></small>
            </span>
        </a>
        <div class="sp-sidebar-actions">
            <a href="<?php echo $e($siteUrl); ?>" target="_blank" rel="noopener noreferrer">查看站点 ↗</a>
            <a href="<?php echo $e($logoutUrl); ?>">登出</a>
        </div>
    </div>
</aside>
