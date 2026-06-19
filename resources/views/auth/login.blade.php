<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WP Gorila — Acesso</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            background-color: #0f0f0f;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding: 1rem;
        }

        .card {
            width: 100%;
            max-width: 380px;
            background-color: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 2.5rem 2rem;
        }

        .brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            background-color: #25d366;
            border-radius: 14px;
            margin-bottom: 1rem;
        }

        .brand-icon svg {
            width: 28px;
            height: 28px;
            fill: #ffffff;
        }

        .brand-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.02em;
        }

        .brand-subtitle {
            font-size: 0.8rem;
            color: #555555;
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
            background-color: #111111;
            border: 1px solid #2e2e2e;
            border-radius: 8px;
            padding: 0.65rem 0.875rem;
            font-size: 0.95rem;
            color: #f0f0f0;
            outline: none;
            transition: border-color 0.15s ease;
            -webkit-appearance: none;
        }

        .field input::placeholder {
            color: #3d3d3d;
        }

        .field input:focus {
            border-color: #25d366;
        }

        .field input:-webkit-autofill {
            -webkit-box-shadow: 0 0 0 1000px #111111 inset;
            -webkit-text-fill-color: #f0f0f0;
        }

        .submit {
            margin-top: 1.5rem;
            width: 100%;
            background-color: #25d366;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s ease, opacity 0.15s ease;
            letter-spacing: 0.01em;
        }

        .submit:hover {
            background-color: #20bc5a;
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
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                    <path d="M12 0C5.373 0 0 5.373 0 12c0 2.125.558 4.12 1.533 5.851L.057 23.885a.75.75 0 0 0 .914.944l6.206-1.629A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.9 0-3.68-.508-5.21-1.393l-.374-.218-3.883 1.018 1.036-3.785-.24-.389A9.953 9.953 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>
                </svg>
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
