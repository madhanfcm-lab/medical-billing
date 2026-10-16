<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/2.1.4/toastr.min.css" rel="stylesheet">
<link href="{{ asset('css/app.css') }}" rel="stylesheet">
<script>
try {
    (function () {
        var t = localStorage.getItem('clinic_theme') || 'system';
        var isDarkSys = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var effective = t === 'system' ? (isDarkSys ? 'dark' : 'light') : t;
        var isDark = effective === 'dark' || effective === 'navy' || effective === 'emerald';
        document.documentElement.setAttribute('data-theme', effective);
        document.documentElement.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');
    })();
} catch (e) {}
</script>
