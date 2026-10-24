<?php
/** Cloud video controls in the first-screen maintenance tab. */

function siren_operations_cloud_video()
{
    $settings = siren_cloud_video_settings();
    ?>
    <form id="siren-cloud-video-form" class="siren-cloud-video">
        <h2>移动云盘视频</h2>
        <p class="description">固定播放地址可填入“主题设置 → 第一屏”的视频列表。</p>
        <table class="form-table" role="presentation">
            <tr><th scope="row">公开播放</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?>>启用固定播放入口</label></td></tr>
            <tr><th scope="row"><label for="siren-cloud-auth">Auth</label></th><td>
                <input type="password" class="large-text" id="siren-cloud-auth" name="auth" autocomplete="new-password" spellcheck="false"
                    placeholder="<?php echo $settings['auth'] !== '' ? '已保存，留空保留' : 'Basic 开头的完整 Authorization'; ?>">
                <p class="description">填写预览请求的 Authorization，包含 Basic 前缀，留空保留原值。</p>
            </td></tr>
            <tr><th scope="row"><label for="siren-cloud-cookie">Cookie</label></th><td>
                <input type="password" class="large-text" id="siren-cloud-cookie" name="cookie" autocomplete="new-password" spellcheck="false"
                    placeholder="<?php echo $settings['cookie'] !== '' ? '已保存，留空保留' : '选填，Cookie 请求头的整行内容'; ?>">
                <p class="description">填写预览请求的 Cookie，留空保留原值。</p>
                <label><input type="checkbox" name="clear_credentials" value="1">清除已保存的 Auth 和 Cookie</label>
            </td></tr>
            <tr><th scope="row"><label for="siren-cloud-host">预览接口服务器</label></th><td>
                <input type="url" class="large-text code" id="siren-cloud-host" name="host" required value="<?php echo esc_attr($settings['host']); ?>">
                <p class="description">填写 getPreviewInfo 请求的 personal 服务器地址，不含路径。</p>
            </td></tr>
            <tr><th scope="row"><label for="siren-cloud-minutes">检查间隔</label></th><td>
                <input type="number" class="small-text" id="siren-cloud-minutes" name="minutes" min="5" max="1440" required value="<?php echo esc_attr($settings['minutes']); ?>"> 分钟
                <p class="description">超过此间隔，或距链接到期不足 5 分钟时，下次播放会更新链接。</p>
            </td></tr>
            <tr><th scope="row"><label for="siren-cloud-videos">允许更新的视频</label></th><td>
                <textarea class="large-text code" id="siren-cloud-videos" name="videos" rows="4" spellcheck="false"
                    placeholder="https://yun.139.com/w/#/vplay?contentID=…"><?php echo esc_textarea(implode("\n", $settings['videos'])); ?></textarea>
                <p class="description">每行一个云盘播放页链接或文件 ID，仅列表内的视频可公开播放。</p>
            </td></tr>
        </table>
        <button type="submit" class="button button-primary">保存云盘配置</button>
        <div id="siren-cloud-video-result" class="siren-operation-result" role="status" aria-live="polite" hidden></div>
    </form>
    <?php if ($settings['videos']) : ?>
        <div class="siren-cloud-video-links">
            <h2>固定播放地址</h2>
            <p class="description">点击“更新链接”测试授权，Auth 失效后需重新填写。</p>
            <?php foreach ($settings['videos'] as $id) :
                $cache = get_transient(siren_cloud_video_cache_key($settings, $id)); ?>
                <div class="siren-cloud-video-entry">
                    <label class="screen-reader-text" for="siren-cloud-link-<?php echo esc_attr($id); ?>">视频固定地址</label>
                    <input type="text" readonly class="large-text code" id="siren-cloud-link-<?php echo esc_attr($id); ?>" value="<?php echo esc_url(siren_cloud_video_url($id)); ?>">
                    <p class="description"><?php echo $cache ? '下次检查：' . esc_html(wp_date('Y-m-d H:i:s', $cache['refresh_at'])) : '尚未获取播放地址'; ?></p>
                    <button type="button" class="button" data-cloud-video="<?php echo esc_attr($id); ?>">更新链接</button>
                    <p class="siren-operation-result" role="status" aria-live="polite" hidden></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;
}
