@php
    $endpoints = [
        ['POST', '/api/v1/auth/token', 'Issue a personal access token (email + password).'],
        ['GET', '/api/v1/auth/user', 'Return the user owning the current token.'],
        ['DELETE', '/api/v1/auth/token', 'Revoke the token used for the request.'],
        ['GET', '/api/v1/shifts', 'Paginated list of daily shifts with platform earnings.'],
        ['POST', '/api/v1/shifts', 'Start a daily shift.'],
        ['GET', '/api/v1/shifts/{shift}', 'Show a single shift.'],
        ['POST', '/api/v1/shifts/{shift}/close', 'Close a shift and compute net profitability.'],
        ['GET', '/api/v1/fuel-invoices', 'Paginated list of uploaded fuel invoices.'],
        ['POST', '/api/v1/fuel-invoices', 'Upload a fuel invoice image and queue OCR.'],
        ['GET', '/api/v1/fuel-invoices/{fuelInvoice}', 'Show a single fuel invoice.'],
        ['DELETE', '/api/v1/fuel-invoices/{fuelInvoice}', 'Delete a fuel invoice.'],
        ['GET', '/api/v1/stats/weekly', 'Aggregated metrics for the last 8 weeks.'],
        ['GET', '/api/v1/stats/monthly', 'Aggregated metrics for the last 6 months.'],
        ['GET', '/api/v1/stats/summary', 'Global totals, averages, best and worst shift.'],
        ['GET', '/api/v1/stats/efficiency', 'Profit per km/hour and fuel cost per km.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — API</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root {
            --surface: #0F172A;
            --surface-raised: #1E293B;
            --border: #334155;
            --text: #E2E8F0;
            --muted: #94A3B8;
            --primary: #10B981;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--surface);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
        }

        .wrap {
            max-width: 880px;
            margin: 0 auto;
            padding: 64px 24px 96px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px 14px;
            font-size: 12px;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: var(--primary);
        }

        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--primary);
        }

        h1 {
            font-size: clamp(2rem, 5vw, 2.75rem);
            font-weight: 600;
            letter-spacing: -.02em;
            margin: 24px 0 12px;
        }

        h2 {
            font-size: .78rem;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
            margin: 48px 0 16px;
        }

        p { color: var(--muted); margin: 0 0 16px; }

        .lead { font-size: 1.05rem; max-width: 62ch; }

        .grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .card {
            background: var(--surface-raised);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px 20px;
        }

        .card .label {
            font-size: .72rem;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .card .value { font-size: 1rem; color: var(--text); }
        .card a { color: var(--primary); text-decoration: none; }
        .card a:hover { text-decoration: underline; }

        ul.endpoints {
            list-style: none;
            padding: 0;
            margin: 0;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        ul.endpoints li {
            display: flex;
            gap: 14px;
            align-items: baseline;
            padding: 11px 18px;
            background: var(--surface-raised);
            border-bottom: 1px solid var(--border);
            font-size: .875rem;
        }

        ul.endpoints li:last-child { border-bottom: 0; }

        .method {
            flex: 0 0 62px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .72rem;
            letter-spacing: .06em;
            color: var(--primary);
        }

        .path {
            flex: 0 1 auto;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            color: var(--text);
        }

        .desc {
            flex: 1 1 auto;
            text-align: right;
            color: var(--muted);
        }

        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .82rem;
            background: #0B1220;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 2px 6px;
            color: var(--text);
        }

        pre {
            background: #0B1220;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 18px;
            overflow-x: auto;
            font-size: .82rem;
            margin: 0 0 16px;
        }

        pre code { background: none; border: 0; padding: 0; }

        footer {
            margin-top: 64px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
            font-size: .82rem;
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            gap: 8px 24px;
            justify-content: space-between;
        }

        footer a { color: var(--muted); }
        footer a:hover { color: var(--primary); }
    </style>
</head>
<body>
<div class="wrap">
    <span class="badge"><span class="dot"></span> API service</span>

    <h1>{{ config('app.name') }}</h1>

    <p class="lead">
        This project is the backend of the Ride Efficiency platform. It exposes a JSON API for
        tracking gig-economy shift profitability: GPS kilometres, connected time, per-platform
        earnings, fuel invoices with OCR extraction, depreciation and net profit per shift.
    </p>

    <p class="lead">
        There is no web interface here. All responses are JSON, authenticated with Laravel Sanctum
        bearer tokens. The dashboard and the mobile application are separate repositories that
        consume this API.
    </p>

    <h2>Owner</h2>
    <div class="grid">
        <div class="card">
            <div class="label">Maintainer</div>
            <div class="value">Julio Serpone</div>
        </div>
        <div class="card">
            <div class="label">Repository</div>
            <div class="value">
                <a href="https://github.com/julioserpone/ride-efficiency-api" rel="noopener">github.com/julioserpone/ride-efficiency-api</a>
            </div>
        </div>
        <div class="card">
            <div class="label">License</div>
            <div class="value">MIT</div>
        </div>
        <div class="card">
            <div class="label">Health check</div>
            <div class="value"><a href="/up" rel="noopener">GET /up</a></div>
        </div>
    </div>

    <h2>Consumers</h2>
    <div class="grid">
        <div class="card">
            <div class="label">Web dashboard</div>
            <div class="value">
                <a href="https://github.com/julioserpone/ride-efficiency-frontend" rel="noopener">ride-efficiency-frontend</a>
            </div>
        </div>
        <div class="card">
            <div class="label">Mobile application</div>
            <div class="value">
                <a href="https://github.com/julioserpone/ride-efficiency-mobile" rel="noopener">ride-efficiency-mobile</a>
            </div>
        </div>
        <div class="card">
            <div class="label">Stack</div>
            <div class="value">Vue 3 + PrimeVue SPA &middot; React Native</div>
        </div>
    </div>

    <h2>Authentication</h2>
    <p>Request a token with your credentials, then send it as a bearer header on every call.</p>
<pre><code>curl -X POST {{ url('/api/v1/auth/token') }} \
  -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","password":"secret","device_name":"dashboard"}'

curl {{ url('/api/v1/stats/summary') }} \
  -H "Authorization: Bearer &lt;token&gt;"</code></pre>

    <h2>Endpoints</h2>
    <ul class="endpoints">
        @foreach ($endpoints as [$method, $path, $description])
            <li>
                <span class="method">{{ $method }}</span>
                <span class="path">{{ $path }}</span>
                <span class="desc">{{ $description }}</span>
            </li>
        @endforeach
    </ul>

    <footer>
        <span>&copy; {{ date('Y') }} Julio Serpone. All rights reserved.</span>
        <span>{{ config('app.name') }} &middot; Laravel {{ app()->version() }}</span>
    </footer>
</div>
</body>
</html>
