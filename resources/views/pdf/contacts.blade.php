<!doctype html>
{{--
    Lista de contatos do evento, para o facilitador.

    Dado pessoal de 150 pessoas num arquivo só: sai apenas pelo endpoint com
    token de master, e o rodapé carrega a data para que uma cópia velha se
    identifique como velha.
--}}
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Contatos — {{ $event->title }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #111827; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .sub { color: #6b7280; font-size: 9px; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .08em;
             color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 0 6px 5px; }
        td { padding: 5px 6px; border-bottom: 1px solid #f3f4f6; }
        tr:nth-child(even) td { background: #fafafa; }
        .num { color: #9ca3af; width: 22px; }
        .name { font-weight: bold; }
        .muted { color: #6b7280; }
        .foot { margin-top: 14px; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    <h1>{{ $event->title }} — contatos</h1>
    <p class="sub">
        {{ $people->count() }} {{ $people->count() === 1 ? 'pessoa' : 'pessoas' }}
        · gerado em {{ $generatedAt }}
    </p>

    <table>
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Nome</th>
                <th>Contato</th>
                <th>Hotel</th>
                <th>Mesa</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($people as $i => $person)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td class="name">{{ $person->name }}</td>
                    <td>{{ $person->contact() ?: '—' }}</td>
                    <td class="muted">{{ $person->hotel ?: '—' }}</td>
                    <td class="muted">{{ $person->table?->name ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="foot">
        Sistema IO · Sala de Decisões ROC — documento com dados pessoais dos participantes.
    </p>
</body>
</html>
