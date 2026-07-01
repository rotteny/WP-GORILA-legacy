<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WP Gorila — Acesso</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background-color: #212121;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            padding: 1rem;
        }

        .card {
            width: 100%;
            max-width: 380px;
            background-color: #2b2b2b;
            border: 1px solid #3a3a3a;
            border-radius: 18px;
            padding: 2.5rem 2rem;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.5);
        }

        .brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background-color: #ffffff;
            border-radius: 16px;
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.02em;
        }

        .brand-subtitle {
            font-size: 0.8rem;
            color: #8a8a8a;
            margin-top: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .errors {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
        }

        .errors ul {
            list-style: none;
        }

        .errors li {
            font-size: 0.825rem;
            color: #f87171;
            line-height: 1.5;
        }

        .errors li + li {
            margin-top: 0.25rem;
        }

        .field {
            margin-bottom: 1rem;
        }

        .field label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
            color: #888888;
            margin-bottom: 0.4rem;
            letter-spacing: 0.03em;
        }

        .field input {
            width: 100%;
            background-color: #242424;
            border: 1px solid #3a3a3a;
            border-radius: 11px;
            padding: 0.7rem 0.95rem;
            font-size: 0.95rem;
            color: #f0f0f0;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            font-family: inherit;
            -webkit-appearance: none;
        }

        .field input::placeholder {
            color: #8a8a8a;
        }

        .field input:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.14);
        }

        .field input:-webkit-autofill {
            -webkit-box-shadow: 0 0 0 1000px #242424 inset;
            -webkit-text-fill-color: #f0f0f0;
        }

        .submit {
            margin-top: 1.5rem;
            width: 100%;
            background-color: #8b5cf6;
            color: #ffffff;
            border: none;
            border-radius: 13px;
            padding: 0.8rem 1rem;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.1s ease;
            letter-spacing: 0.01em;
            font-family: inherit;
        }

        .submit:hover {
            background-color: #7c3aed;
        }

        .submit:active {
            opacity: 0.85;
        }

        @media (max-width: 420px) {
            .card {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>

    <div class="card" role="main">

        <div class="brand">
            <div class="brand-icon" aria-hidden="true">
                <img src="/img/gorila.png" alt="WP Gorila">
            </div>
            <div class="brand-title">WP Gorila</div>
            <div class="brand-subtitle">Painel interno</div>
        </div>

        @if ($errors->any())
            <div class="errors" role="alert" aria-label="Erros de validação">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('login') }}" method="POST" novalidate>
            @csrf

            <div class="field">
                <label for="email">E-mail</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    placeholder="seu@email.com"
                    autocomplete="email"
                    autofocus
                    required
                >
            </div>

            <div class="field">
                <label for="password">Senha</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit" class="submit">Entrar</button>
        </form>

    </div>

</body>
</html>
