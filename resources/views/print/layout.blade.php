<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; color: #111; margin: 0; padding: 16px; font-size: 13px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: start; }
        th { background: #f1f1f1; }
        .num { text-align: end; }
        .muted { color: #555; }
        .row { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .sign { margin-top: 40px; display: flex; gap: 24px; }
        .sign div { flex: 1; border-top: 1px solid #333; padding-top: 6px; min-height: 60px; }
        .no-print { margin-bottom: 12px; }
        .no-print button { font-size: 16px; padding: 10px 20px; cursor: pointer; }
        @media print { .no-print { display: none; } body { padding: 0; } }
        @yield('style')
    </style>
</head>
<body>
    <div class="no-print"><button onclick="window.print()">🖨 {{ __('app.print') }}</button></div>
    @yield('content')
</body>
</html>
