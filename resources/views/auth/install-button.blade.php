{{-- "Install the app" bar at the bottom of the login page (PWA). Hidden once the app is installed. --}}
<div id="pm-install" style="display: none; position: fixed; inset-inline: 0; bottom: 0; z-index: 50; padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); background: #ffffff; border-top: 1px solid #e5e7eb; box-shadow: 0 -4px 16px rgba(0,0,0,.08);">
    <button type="button" id="pm-install-btn"
        style="width: 100%; min-height: 52px; border-radius: 12px; border: 0; background: #F5B800; color: #1f2937; font-weight: 700; font-size: 1.05rem; display: flex; gap: 10px; align-items: center; justify-content: center; cursor: pointer;">
        <img src="/pwa/icon-192.png" alt="" style="width: 30px; height: 30px;">
        {{ __('install.button') }}
    </button>
    <div id="pm-install-ios" style="display: none; margin-top: 10px; font-size: .95rem; line-height: 1.6; color: #374151;">
        <b>{{ __('install.ios_title') }}</b><br>
        1. {!! __('install.ios_step1') !!}<br>
        2. {{ __('install.ios_step2') }}
    </div>
</div>
<script>
(function () {
    const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (standalone) return; // already installed: no button
    const bar = document.getElementById('pm-install');
    const btn = document.getElementById('pm-install-btn');
    const iosHelp = document.getElementById('pm-install-ios');
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    let deferred = null;
    // Keep the login button reachable: the page gets room for the bar.
    const show = () => { bar.style.display = 'block'; document.body.style.paddingBottom = (bar.offsetHeight + 16) + 'px'; };
    const hide = () => { bar.style.display = 'none'; document.body.style.paddingBottom = ''; };

    // Android / Chrome / Edge: the browser offers its own install prompt.
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferred = event;
        show();
    });
    window.addEventListener('appinstalled', hide);

    // iPhone / iPad: Safari has no install prompt, the button shows the two steps.
    if (isIos) show();

    btn.addEventListener('click', async () => {
        if (deferred) {
            deferred.prompt();
            const choice = await deferred.userChoice;
            if (choice.outcome === 'accepted') hide();
            deferred = null;
        } else {
            iosHelp.style.display = iosHelp.style.display === 'block' ? 'none' : 'block';
            if (!isIos) iosHelp.innerHTML = @json(__('install.other'));
            show();
            if (iosHelp.style.display === 'block') iosHelp.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }
    });
})();
</script>
