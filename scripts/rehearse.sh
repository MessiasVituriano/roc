#!/usr/bin/env bash
#
# Ensaio completo: do evento vazio até a tela de premiação, sem tocar em
# nenhum botão. Serve para validar as telas no navegador com dados densos.
#
#   ./scripts/rehearse.sh [até]
#
# `até` escolhe onde parar, para inspecionar cada momento:
#   lobby        sala enchendo, ninguém votou
#   reveal       revelação da rodada 1 (distribuição, sem gabarito)
#   missions     virada de fase (placar por missão)
#   comparison   gabarito + comparativo Fase 1 × Fase 2
#   final        premiação — pódio, campeões e destaques individuais (padrão)
#
# Pressupõe o servidor de desenvolvimento no ar em CONTAINER (veja o README).
set -euo pipefail

STOP="${1:-final}"
BASE="${BASE_URL:-http://localhost:8000}"
CONTAINER="${DEV_CONTAINER:-roc-dev}"
TOKEN="${MASTER_TOKEN:-master-dev-token}"
PEOPLE="${PEOPLE:-72}"

api() { curl -sf -X POST -H "X-Master-Token: $TOKEN" "$BASE/api/admin/$1" >/dev/null; }
demo() { docker exec "$CONTAINER" php artisan live:demo "$@" >/dev/null; }
step() { printf '  %-12s %s\n' "$1" "$2"; }

echo "Ensaiando em $BASE (parando em: $STOP)"

api reset-event
api open
demo --participants="$PEOPLE"
step lobby "$PEOPLE participantes em 20 mesas"
[ "$STOP" = lobby ] && exit 0

# --- Fase 1: 5 rodadas individuais ---------------------------------------
# a adesão cai um pouco a cada rodada, como numa sala real
for round in 1 2 3 4 5; do
    api start
    demo --participants=0 --progress=$((98 - round * 3))
    api reveal
    step "F1 rodada $round" "revelado"
    [ "$STOP" = reveal ] && exit 0
    [ "$round" -lt 5 ] && api next
done

api missions/reveal
step missions "placar por missão no telão"
[ "$STOP" = missions ] && exit 0

# --- Fase 2: as mesmas 5 perguntas, agora por mesa -----------------------
api next-phase
for round in 1 2 3 4 5; do
    api start
    demo --participants=0 --progress=100
    api reveal
    step "F2 rodada $round" "consenso registrado"
    [ "$round" -lt 5 ] && api next
done

api answers/reveal
step comparison "gabarito + comparativo liberados"
[ "$STOP" = comparison ] && exit 0

api end
step final "premiação no telão"
