<?php
/*
Plugin Name: 友链 Plus 异步检测版（veryjack风格）
Description: 友链展示+RSS抓取+链接存活检测，后台异步刷新检测状态，支持缓存清理和CSV导出，前端样式仿 veryjack.com。
Version: 1.7
Author: 段先森
Author URI: https://www.duanxiansen.com/
*/

// ----------- 前台短代码友链渲染 -----------
add_shortcode('friend_links', 'flp_render_friend_links');
function flp_render_friend_links() {
    $output = '<div class="friend-links">';
    $bookmarks = get_bookmarks(['orderby' => 'name', 'hide_invisible' => 1]);
    if (!$bookmarks) {
        $output .= '<p>暂无友链。</p></div>';
        return $output;
    }

    $click_counts = [];
    foreach ($bookmarks as $link) {
        $click_counts[$link->link_id] = flp_get_click_count($link->link_id);
    }
    $max_clicks = max($click_counts) ?: 1;

    foreach ($bookmarks as $link) {
        $rss = $link->link_rss ? $link->link_rss : rtrim($link->link_url, '/') . '/feed/';
        $rss_result = flp_get_rss_items($rss, 3);
        $rss_valid = is_array($rss_result) && !empty($rss_result['valid']) && $rss_result['valid'];
        $items = $rss_result['items'] ?? [];

        $favicon = 'https://www.google.com/s2/favicons?sz=64&domain_url=' . urlencode($link->link_url);
        $desc = esc_html($link->link_description);
        $latest_time = !empty($items) ? date('Y-m-d', strtotime($items[0]['date'])) : '';
        $is_alive = flp_check_link_alive($link->link_url);
        $card_class = $is_alive ? 'friend-card' : 'friend-card friend-dead';
        $title_class = $rss_valid ? 'friend-title' : 'friend-title friend-rss-invalid';

        $clicks = $click_counts[$link->link_id];
        $heat_width = intval($clicks / $max_clicks * 100);

        $output .= '<div class="' . $card_class . '">';
        $output .= '<div class="friend-header">';
        $output .= '<img src="' . esc_url($favicon) . '" alt="icon" class="friend-favicon" loading="lazy">';
        $output .= '<div class="friend-meta">';
        $output .= '<div class="' . $title_class . '"><a href="' . esc_url($link->link_url) . '" target="_blank" rel="nofollow">' . esc_html($link->link_name) . '</a></div>';
        if (!$rss_valid) {
            $output .= '<div class="friend-rss-warning" title="RSS 无效或抓取失败">⚠ RSS 无效</div>';
        }
        if ($desc) {
            $output .= '<div class="friend-desc" title="' . $desc . '">' . wp_trim_words($desc, 20, '...') . '</div>';
        }
        $output .= '</div></div>'; // .friend-header, .friend-meta

        if (!empty($items)) {
            $output .= '<ul class="friend-posts">';
            foreach ($items as $item) {
                $title = esc_html($item['title']);
                $link_url = esc_url($item['link']);
                $output .= '<li><a href="' . $link_url . '" target="_blank" rel="nofollow" title="' . $title . '">' . wp_trim_words($title, 8, '...') . '</a></li>';
            }
            $output .= '</ul>';
        }

        if ($latest_time) {
            $output .= '<div class="friend-time">更新于：' . $latest_time . '</div>';
        }

        if (!$is_alive) {
            $output .= '<div class="friend-dead-notice" title="链接可能失效">⚠ 链接异常</div>';
        }

        $output .= '<div class="friend-heatbar"><div style="width:' . $heat_width . '%"></div></div>';
        $output .= '</div>';
    }
    $output .= '</div>';
    return $output;
}

// ----------- RSS 抓取缓存 -----------
function flp_get_rss_items($feed_url, $limit = 3) {
    $cache_key = 'flp_rss_' . md5($feed_url);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    include_once ABSPATH . WPINC . '/feed.php';
    $rss = fetch_feed($feed_url);

    $result = [
        'valid' => false,
        'items' => [],
    ];

    if (!is_wp_error($rss)) {
        $rss_items = $rss->get_items(0, $limit);
        if ($rss_items && count($rss_items) > 0) {
            $result['valid'] = true;
            foreach ($rss_items as $item) {
                $result['items'][] = [
                    'title' => $item->get_title(),
                    'link'  => $item->get_permalink(),
                    'date'  => $item->get_date('Y-m-d H:i:s'),
                ];
            }
        }
    }

    set_transient($cache_key, $result, HOUR_IN_SECONDS);
    return $result;
}

// ----------- 链接存活检测缓存 -----------
function flp_check_link_alive($url) {
    $cache_key = 'flp_alive_' . md5($url);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $response = wp_remote_head($url, ['timeout' => 5, 'redirection' => 3]);
    $alive = (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 200);
    set_transient($cache_key, $alive, 12 * HOUR_IN_SECONDS);
    return $alive;
}

// ----------- 点击量读取（示例，需安装WP Statistics或兼容插件）-----------
function flp_get_click_count($link_id) {
    global $wpdb;
    $path = '/goto/' . $link_id;
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}statistics_historical
        WHERE uri = %s
    ", $path));
    return intval($count);
}

// ----------- 样式加载 -----------
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('friend-links-style', plugin_dir_url(__FILE__) . 'friend-links-style.css');
});

// ----------- 后台菜单和页面 -----------
add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        '友链 RSS 状态检测',
        '友链 RSS 状态检测',
        'manage_options',
        'flp_rss_status',
        'flp_rss_status_page'
    );
});

// ----------- Ajax 接口，异步检测单条友链 -----------
add_action('wp_ajax_flp_check_single_link', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('无权限');
    }

    check_ajax_referer('flp_check_single_link_nonce', 'nonce');

    $link_id = intval($_POST['link_id']);
    $link = get_bookmark($link_id);
    if (!$link) {
        wp_send_json_error('友链不存在');
    }

    $rss_url = $link->link_rss ? $link->link_rss : rtrim($link->link_url, '/') . '/feed/';
    $rss_result = flp_get_rss_items($rss_url, 1);
    $rss_valid = is_array($rss_result) && !empty($rss_result['valid']) && $rss_result['valid'];

    $alive = flp_check_link_alive($link->link_url);

    wp_send_json_success([
        'rss_valid' => $rss_valid,
        'alive' => $alive,
    ]);
});

// ----------- 后台页面展示 -----------
add_action('admin_init', function () {
    if (isset($_GET['flp_export_rss_status']) && current_user_can('manage_options')) {
        flp_export_rss_status_csv();
        exit;
    }
});

function flp_rss_status_page() {
    if (!current_user_can('manage_options')) {
        wp_die('无权限访问');
    }

    // 处理缓存清理请求
    if (isset($_POST['flp_clear_cache_nonce']) && wp_verify_nonce($_POST['flp_clear_cache_nonce'], 'flp_clear_cache_action')) {
        flp_clear_all_cache();
        echo '<div class="updated"><p>友链缓存已清理！</p></div>';
    }

    $bookmarks = get_bookmarks(['orderby' => 'name', 'hide_invisible' => 1]);

    echo '<div class="wrap">';
    echo '<h1>友链 RSS 状态检测</h1>';

    echo '<form method="post">';
    wp_nonce_field('flp_clear_cache_action', 'flp_clear_cache_nonce');
    echo '<input type="submit" class="button button-primary" value="清理所有友链缓存">';
    echo '</form>';

    echo ' <a href="' . esc_url(admin_url('tools.php?page=flp_rss_status&flp_export_rss_status=1')) . '" class="button" style="margin-left:10px;">导出 CSV</a>';

    if (!$bookmarks) {
        echo '<p>暂无友链。</p></div>';
        return;
    }

    echo '<table class="widefat fixed striped" style="max-width:1000px; margin-top:15px;">';
    echo '<thead><tr>';
    echo '<th>名称</th><th>链接</th><th>RSS 地址</th><th>RSS 状态</th><th>链接存活</th><th>说明</th>';
    echo '</tr></thead><tbody>';

    $nonce = wp_create_nonce('flp_check_single_link_nonce');

    foreach ($bookmarks as $link) {
        $rss_url = $link->link_rss ? $link->link_rss : rtrim($link->link_url, '/') . '/feed/';
        $note = '';
        if (!$link->link_rss) {
            $note = '未填写 RSS，使用默认推测地址';
        }
        echo '<tr data-link-id="' . esc_attr($link->link_id) . '">';
        echo '<td>' . esc_html($link->link_name) . '</td>';
        echo '<td><a href="' . esc_url($link->link_url) . '" target="_blank" rel="nofollow">' . esc_html($link->link_url) . '</a></td>';
        echo '<td><a href="' . esc_url($rss_url) . '" target="_blank" rel="nofollow">' . esc_html($rss_url) . '</a></td>';
        echo '<td class="rss-status"><span style="color:#999;">加载中...</span></td>';
        echo '<td class="alive-status"><span style="color:#999;">加载中...</span></td>';
        echo '<td>' . esc_html($note) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    ?>
    <script>
    (function(){
        const nonce = '<?php echo $nonce; ?>';
        const rows = document.querySelectorAll('tr[data-link-id]');
        rows.forEach(row => {
            const linkId = row.getAttribute('data-link-id');
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'flp_check_single_link',
                    link_id: linkId,
                    nonce: nonce
                })
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    row.querySelector('.rss-status').innerHTML = data.data.rss_valid ? '<span style="color:green;">有效</span>' : '<span style="color:red;">无效</span>';
                    row.querySelector('.alive-status').innerHTML = data.data.alive ? '<span style="color:green;">存活</span>' : '<span style="color:red;">失效</span>';
                } else {
                    row.querySelector('.rss-status').innerHTML = '<span style="color:gray;">错误</span>';
                    row.querySelector('.alive-status').innerHTML = '<span style="color:gray;">错误</span>';
                }
            });
        });
    })();
    </script>
    <?php
    echo '</div>';
}

// ----------- 清理缓存 -----------
function flp_clear_all_cache() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_flp_rss_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_flp_rss_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_flp_alive_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_flp_alive_%'");
}

// ----------- CSV 导出 -----------
function flp_export_rss_status_csv() {
    if (!current_user_can('manage_options')) {
        wp_die('无权限访问');
    }

    $bookmarks = get_bookmarks(['orderby' => 'name', 'hide_invisible' => 1]);
    if (!$bookmarks) {
        wp_die('暂无友链');
    }

    $filename = 'friend-links-rss-status-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');
    fputcsv($output, ['名称', '链接', 'RSS 地址', 'RSS 状态', '链接存活', '说明']);

    foreach ($bookmarks as $link) {
        $rss_url = $link->link_rss ? $link->link_rss : rtrim($link->link_url, '/') . '/feed/';
        $rss_result = flp_get_rss_items($rss_url, 1);
        $rss_valid = is_array($rss_result) && !empty($rss_result['valid']) && $rss_result['valid'];

        $alive = flp_check_link_alive($link->link_url);

        $rss_status = $rss_valid ? '有效' : '无效';
        $alive_status = $alive ? '存活' : '失效';

        $note = '';
        if (!$link->link_rss) {
            $note = '未填写 RSS，使用默认推测地址';
        }
        if (!$rss_valid) {
            $note .= '；RSS抓取失败';
        }
        if (!$alive) {
            $note .= '；链接疑似失效';
        }

        fputcsv($output, [
            $link->link_name,
            $link->link_url,
            $rss_url,
            $rss_status,
            $alive_status,
            $note,
        ]);
    }

    fclose($output);
    exit;
}
