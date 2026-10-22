/* Each maintenance action reports its result in place. */
document.addEventListener('DOMContentLoaded', function () {
    const button = document.getElementById('siren-update-ip');
    if (!button) return;

    const result = document.getElementById('siren-ip-result');
    const message = document.getElementById('siren-ip-message');
    const details = document.getElementById('siren-ip-details');
    const spinner = result.querySelector('.spinner');
    const proxy = document.getElementById('siren-ip-proxy');

    button.addEventListener('click', async function () {
        const started = performance.now();
        button.disabled = true;
        proxy.disabled = true;
        result.hidden = false;
        result.dataset.state = 'pending';
        result.setAttribute('aria-busy', 'true');
        spinner.classList.add('is-active');
        details.hidden = true;
        message.textContent = '正在下载并处理 APNIC 数据……';

        try {
            const response = await fetch(sirenOperations.url, {
                method: 'POST',
                body: new URLSearchParams({action: 'siren_update_ip_database', nonce: sirenOperations.nonce,
                    proxy: proxy.value.trim()})
            });
            const payload = await response.json();
            if (!payload.success) throw new Error(payload.data.message);

            const status = payload.data.status;
            document.querySelectorAll('[data-ip-status]').forEach(function (field) {
                field.textContent = status[field.dataset.ipStatus];
            });
            result.dataset.state = 'success';
            message.textContent = payload.data.message;
            details.textContent = 'APNIC ' + status.serial + '；IPv4 ' + status.ipv4 +
                ' 段，IPv6 ' + status.ipv6 + ' 段。耗时 ' + ((performance.now() - started) / 1000).toFixed(1) + ' 秒。';
            details.hidden = false;
        } catch (error) {
            result.dataset.state = 'error';
            message.textContent = error instanceof TypeError || error instanceof SyntaxError
                ? '未能取得更新结果，请刷新查看库状态后再试。' : error.message;
        } finally {
            spinner.classList.remove('is-active');
            result.setAttribute('aria-busy', 'false');
            button.disabled = false;
            proxy.disabled = false;
        }
    });

    const testForm = document.getElementById('siren-test-ip-form');
    const testButton = testForm.querySelector('button');
    const testResult = document.getElementById('siren-test-ip-result');
    testForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        testButton.disabled = true;
        testResult.hidden = false;
        testResult.dataset.state = 'pending';
        testResult.setAttribute('aria-busy', 'true');
        testResult.textContent = '正在查询本地 IP 库……';
        try {
            const response = await fetch(sirenOperations.url, {
                method: 'POST',
                body: new URLSearchParams({action: 'siren_test_ip', nonce: sirenOperations.testNonce,
                    ip: testForm.elements.ip.value.trim()})
            });
            const payload = await response.json();
            if (!payload.success) throw new Error(payload.data.message);
            testResult.dataset.state = 'success';
            testResult.textContent = payload.data.message;
        } catch (error) {
            testResult.dataset.state = 'error';
            testResult.textContent = error instanceof TypeError || error instanceof SyntaxError
                ? '查询失败，请稍后重试。' : error.message;
        } finally {
            testResult.setAttribute('aria-busy', 'false');
            testButton.disabled = false;
        }
    });
});
