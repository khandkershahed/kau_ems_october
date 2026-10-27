<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - Khulna Agricultural University</title>
    <link rel="icon" href="/logo.png" type="image/png">

    <!-- Modern Typography -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900&display=swap" rel="stylesheet" />

    <script>
        // Check local storage or system preference for dark mode
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <style>
        *, ::before, ::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: poppins, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            /* font-family: 'Figtree', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background-color: #f8fafc;
            color: #0f172a;
            overflow-x: hidden;
            transition: background-color 0.3s, color 0.3s;
        }
        .dark body {
            background-color: #030712;
            color: #f8fafc;
        }
        .wave-bg {
            position: fixed;
            inset: 0;
            z-index: -10;
            pointer-events: none;
        }
        .container {
            width: 100%;
            max-width: 860px;
            margin: auto;
            padding: 24px;
            text-align: center;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px 32px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .dark .glass-card {
            background: rgba(17, 24, 39, 0.85);
            border-color: rgba(31, 41, 55, 0.8);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }
        .header {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }
        .brand img {
            height: 44px;
            width: 44px;
            object-fit: contain;
        }
        .brand-title {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
        }
        .brand-sub {
            font-size: 11px;
            color: #059669;
            font-weight: 600;
        }
        .dark .brand-sub {
            color: #34d399;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
            border: 1px solid transparent;
        }
        .badge-404 { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .dark .badge-404 { background: rgba(6, 78, 59, 0.5); color: #6ee7b7; border-color: #065f46; }
        .badge-500 { background: #ffe4e6; color: #9f1239; border-color: #fecdd3; }
        .dark .badge-500 { background: rgba(136, 19, 55, 0.5); color: #fda4af; border-color: #881337; }
        .badge-403 { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .dark .badge-403 { background: rgba(120, 53, 15, 0.5); color: #fcd34d; border-color: #78350f; }
        .badge-401 { background: #cffafe; color: #155e75; border-color: #a5f3fc; }
        .dark .badge-401 { background: rgba(22, 78, 99, 0.5); color: #67e8f9; border-color: #164e63; }
        .badge-503 { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .dark .badge-503 { background: rgba(30, 58, 138, 0.5); color: #93c5fd; border-color: #1e3a8a; }

        .error-code {
            font-size: clamp(4rem, 15vw, 7.5rem);
            font-weight: 900;
            line-height: 1;
            font-family: monospace;
            background: linear-gradient(135deg, #0f172a, #059669);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 8px 0;
        }
        .dark .error-code {
            background: linear-gradient(135deg, #ffffff, #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .error-title {
            font-size: 24px;
            font-weight: 800;
            margin-top: 4px;
        }
        .error-bangla {
            font-size: 13px;
            font-weight: 600;
            color: #059669;
            margin-top: 2px;
        }
        .dark .error-bangla {
            color: #34d399;
        }
        .error-desc {
            font-size: 14px;
            color: #475569;
            max-width: 520px;
            margin: 12px auto 0;
            line-height: 1.6;
        }
        .dark .error-desc {
            color: #cbd5e1;
        }
        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            align-items: center;
            margin-top: 28px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
            min-height: 44px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #059669, #0d9488);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
        }
        .btn-primary:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: #f1f5f9;
            color: #334155;
            border-color: #cbd5e1;
        }
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        .dark .btn-secondary {
            background: #1f2937;
            color: #e2e8f0;
            border-color: #374151;
        }
        .dark .btn-secondary:hover {
            background: #374151;
        }
        .footer {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 24px;
            font-size: 12px;
            color: #64748b;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            border-top: 1px solid rgba(226, 232, 240, 0.6);
        }
        .dark .footer {
            border-top-color: rgba(31, 41, 55, 0.6);
            color: #94a3b8;
        }
        @media (max-width: 640px) {
            .btn-group {
                flex-direction: column;
                width: 100%;
            }
            .btn {
                width: 100%;
            }
            .footer {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="wave-bg">
        <svg style="position: absolute; top: 0; left: 0; width: 100%; opacity: 0.15;" viewBox="0 0 1440 320" preserveAspectRatio="none">
            <path fill="#059669" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,165.3C960,192,1056,224,1152,224C1248,224,1344,192,1392,176L1440,160L1440,0L1392,0C1344,0,1248,0,1152,0C1056,0,960,0,864,0C768,0,672,0,576,0C480,0,384,0,288,0C192,0,96,0,48,0L0,0Z"></path>
        </svg>
    </div>

    <header class="header">
        <a href="/" class="brand">
            <img src="/logo.png" alt="KAU Logo" onerror="this.style.display='none'">
            <div>
                <div class="brand-title">Khulna Agricultural University</div>
                <div class="brand-sub">Employee Management System (EMS)</div>
            </div>
        </a>
    </header>

    <main class="container">
        <div class="glass-card">
            @yield('content')
        </div>
    </main>

    <footer class="footer">
        <div>&copy; {{ date('Y') }} Khulna Agricultural University. All rights reserved.</div>
        <div>ICT Cell Helpdesk &bull; <a href="mailto:support@kau.edu.bd" style="color: inherit;">support@kau.edu.bd</a></div>
    </footer>
</body>
</html>
