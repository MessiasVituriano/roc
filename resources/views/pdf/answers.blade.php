<!doctype html>
{{--
    O que a pessoa leva do evento: as cinco decisões dela, lado a lado com a
    melhor decisão, e a **justificativa** de cada alternativa — o campo
    `effect`, que é o que explica por que aquela escolha gera ou destrói valor.

    Sem isso o PDF seria um boletim; com isso ele é o material que faz a
    dinâmica continuar depois que a sala esvazia.
--}}
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Minhas decisões — {{ $event->title }}</title>
    <style>
        @page { margin: 26px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; line-height: 1.45; }
        h1 { font-size: 17px; margin: 0 0 2px; }
        .sub { color: #6b7280; font-size: 9.5px; margin: 0 0 4px; }
        .score { margin: 12px 0 16px; padding: 10px 12px; background: #f0fdf4;
                 border: 1px solid #bbf7d0; border-radius: 6px; }
        .score b { font-size: 20px; }
        .round { margin-bottom: 14px; page-break-inside: avoid; }
        .label { font-size: 8px; text-transform: uppercase; letter-spacing: .1em; color: #b45309; }
        .title { font-weight: bold; font-size: 11px; margin: 1px 0 4px; }
        .context { color: #4b5563; font-size: 9px; margin: 0 0 6px; }
        .opt { padding: 5px 8px; border-left: 3px solid #e5e7eb; margin-bottom: 4px; }
        .opt.best { border-left-color: #16a34a; background: #f0fdf4; }
        .opt.mine { border-left-color: #2563eb; background: #eff6ff; }
        .opt.mine.best { border-left-color: #16a34a; background: #f0fdf4; }
        .opt .head { font-weight: bold; }
        .opt .why { color: #4b5563; font-size: 9px; }
        .tag { font-size: 8px; font-weight: bold; padding: 1px 5px; border-radius: 8px; }
        .tag.best { background: #16a34a; color: #fff; }
        .tag.mine { background: #2563eb; color: #fff; }
        .pts { float: right; font-weight: bold; }
        .foot { margin-top: 16px; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    <h1>{{ $participant->name }} — minhas decisões</h1>
    <p class="sub">{{ $event->title }} · mesa {{ $participant->table?->name ?? '—' }} · {{ $generatedAt }}</p>

    <div class="score">
        Valor gerado decidindo sozinho: <b>{{ $totalPoints }} pts</b>
        · acertou <b>{{ $correct }}</b> de {{ $rounds->count() }} decisões
    </div>

    @foreach ($rounds as $round)
        <div class="round">
            <p class="label">Rodada {{ $round['round'] }} · {{ $round['label'] }}</p>
            <p class="title">{{ $round['title'] }}</p>
            <p class="context">{{ $round['context'] }}</p>

            @foreach ($round['options'] as $option)
                <div class="opt {{ $option['is_best'] ? 'best' : '' }} {{ $option['is_mine'] ? 'mine' : '' }}">
                    <p class="head">
                        <span class="pts">{{ $option['points'] > 0 ? '+' : '' }}{{ $option['points'] }}</span>
                        @if ($option['is_best'])<span class="tag best">MELHOR</span> @endif
                        @if ($option['is_mine'])<span class="tag mine">SUA ESCOLHA</span> @endif
                        {{ $option['text'] }}
                    </p>
                    <p class="why">{{ $option['effect'] }}</p>
                </div>
            @endforeach

            @if (! $round['answered'])
                <p class="context"><em>Você não registrou decisão nesta rodada.</em></p>
            @endif
        </div>
    @endforeach

    <p class="foot">Sistema IO · Sala de Decisões ROC</p>
</body>
</html>
