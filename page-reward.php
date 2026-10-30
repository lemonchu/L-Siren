<?php
/**
 * Template Name: 打赏
 */

get_header();

global $wpdb;
$rewards = $wpdb->get_results($wpdb->prepare(
    "SELECT id, CASE WHEN _is_private = 1 THEN '未留名' ELSE name END AS name,
        method, year, month, day, time, comment
     FROM %i WHERE id > 0
     ORDER BY year DESC, month DESC, day DESC, time DESC, id DESC",
    $wpdb->prefix . 'reward'
));
$unavailable = $wpdb->last_error !== '';
$months = array();
foreach ($rewards as $reward) {
    $month = sprintf('%04d-%02d', $reward->year, $reward->month);
    $months[$month][] = $reward;
}
$methods = array(1 => '微信赞赏', 2 => '微信面对面', 3 => '支付宝');
$style_url = add_query_arg('v', filemtime(get_template_directory() . '/inc/css/reward.css'),
    get_template_directory_uri() . '/inc/css/reward.css');
?>
<!-- Keep this stylesheet inside the PJAX fragment when entering from another page. -->
<link rel="stylesheet" href="<?php echo esc_url($style_url); ?>">
<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('reward-page'); ?>>
                <?php if (akina_option('patternimg') || !get_post_thumbnail_id(get_the_ID())) : ?>
                    <header class="entry-header">
                        <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
                    </header>
                <?php endif; ?>
                <div class="entry-content reward-intro">
                    <?php the_content(); ?>
                </div>
                <section id="archives-temp" class="reward-history" aria-labelledby="reward-history-title">
                    <h2 id="reward-history-title">打赏记录
                        <?php if ($rewards) : ?>
                            <small class="reward-total">（<?php echo esc_html(number_format_i18n(count($rewards))); ?> 笔）</small>
                        <?php endif; ?>
                    </h2>
                    <?php if (!$months) : ?>
                        <p class="reward-empty"><?php echo $unavailable ? '名单暂时无法显示，请稍后再来看看。' : '还没有打赏记录。'; ?></p>
                    <?php endif; ?>
                    <div id="archives-content">
                    <?php foreach ($months as $month => $entries) : ?>
                        <section class="archive-title">
                            <span class="ar-time" aria-hidden="true"><i class="iconfont">&#xe74a;</i></span>
                            <h3><button type="button" class="reward-month-button" aria-controls="reward-month-<?php echo esc_attr($month); ?>">
                                <time datetime="<?php echo esc_attr($month); ?>"><?php echo esc_html($entries[0]->year . '-' . $entries[0]->month); ?></time>
                                <small>（<?php echo esc_html(count($entries)); ?> 笔）</small>
                            </button></h3>
                            <div class="archives" id="reward-month-<?php echo esc_attr($month); ?>" data-date="<?php echo esc_attr($month); ?>">
                                <?php foreach ($entries as $entry) :
                                    $date = sprintf('%04d-%02d-%02d', $entry->year, $entry->month, $entry->day);
                                    $method = $methods[(int) $entry->method] ?? '';
                                    ?>
                                    <div class="reward-entry">
                                        <span class="ar-circle" aria-hidden="true"></span>
                                        <div class="arrow-left-ar" aria-hidden="true"></div>
                                        <div class="brick"><div class="reward-record">
                                            <div class="reward-record-header">
                                                <span class="reward-name"><?php echo esc_html($entry->name !== '' ? $entry->name : '未留名'); ?></span>
                                                <?php if ($method !== '') : ?>
                                                    <em class="reward-method">（<?php echo esc_html($method); ?>）</em>
                                                <?php endif; ?>
                                                <span class="time"><i class="iconfont" aria-hidden="true">&#xe65f;</i><time datetime="<?php echo esc_attr($date . 'T' . $entry->time); ?>"><?php echo esc_html($date . ' ' . $entry->time); ?></time></span>
                                            </div>
                                            <?php if (trim($entry->comment) !== '') : ?>
                                                <em class="reward-note">“<?php echo nl2br(esc_html($entry->comment)); ?>”</em>
                                            <?php endif; ?>
                                        </div></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                    </div>
                </section>
            </article>
        <?php endwhile; ?>
    </main>
</div>
<?php get_footer(); ?>
