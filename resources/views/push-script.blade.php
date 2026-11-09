@php $key = config('services.webpush.public_key'); @endphp
@if ($key)
<script>
window.mouhimEnablePush = async function () {
    try {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) { alert(@json(__('push.unsupported'))); return; }
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') { alert(@json(__('push.denied'))); return; }
        const reg = await navigator.serviceWorker.ready;
        const raw = atob(@json($key).replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - @json($key).length % 4) % 4));
        const key = Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
        const sub = (await reg.pushManager.getSubscription()) || (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }));
        const res = await fetch(@json(route('push.subscribe')), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
            body: JSON.stringify(sub.toJSON()),
        });
        alert(res.ok ? @json(__('push.enabled')) : @json(__('push.failed')));
    } catch (e) { alert(@json(__('push.failed'))); }
};
</script>
@endif
