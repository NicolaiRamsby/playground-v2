<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Playground') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', system-ui, sans-serif;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background: #fafafa;
                color: #1a1a1a;
            }
            .container {
                text-align: center;
                padding: 2rem;
            }
            h1 {
                font-size: 2rem;
                font-weight: 600;
                margin-bottom: 0.5rem;
            }
            p {
                color: #6b7280;
                font-size: 1rem;
            }
            .links {
                margin-top: 2rem;
                display: flex;
                gap: 1rem;
                justify-content: center;
            }
            .links a {
                color: #6b7280;
                text-decoration: none;
                font-size: 0.875rem;
                padding: 0.5rem 1rem;
                border: 1px solid #e5e7eb;
                border-radius: 0.375rem;
                transition: all 0.15s ease;
            }
            .links a:hover {
                color: #1a1a1a;
                border-color: #1a1a1a;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Playground</h1>
            <p>Et sted for småløsninger og automatiseringer.</p>
            <div class="links">
                <a href="/admin">Admin</a>
            </div>
        </div>
    </body>
</html>
