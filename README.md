# Sala de Decisão ROC

Dinâmica de decisão para auditórios, conduzida ao vivo por um facilitador.
Especificação de conteúdo e regras: [`docs/dinamica-roc.md`](docs/dinamica-roc.md).

- **Fase 1 — individual.** 5 rodadas sincronizadas (~20s de voto + revelação).
  Cada pessoa recebe uma **missão sorteada** e decide com ela.
- **Virada de fase.** O placar **por grupo de missão** vai ao telão: mesma
  régua, missões diferentes, decisões diferentes.
- **Fase 2 — mesa.** 1 rodada de consenso, registrada pelo representante.

Cada alternativa vale pontos fixos (**+150 / +80 / 0 / −50**) e nada disso
aparece antes do **clique do facilitador**.

| Tela | URL |
|---|---|
| Participante | `/` (destino do QR Code do telão) |
| **Telão** | **`/display`** |
| Painel Master | `/master` (ou `/master?token=SEU_TOKEN`) |

## Arquitetura

```
PostgreSQL ─ Laravel REST API ─┬─ Participante   (GET /api/status  a cada 1s)
                               ├─ Telão          (GET /api/display a cada 1s)
                               └─ Painel Master  (GET /api/admin/overview a cada 1s)
```

Sem Redis, sem WebSocket, sem filas. Para 150 pessoas em 20 minutos, polling de
1 segundo entrega latência de ~1s com uma fração da complexidade operacional.

O bloco de perguntas **não** trafega no poll: é buscado uma vez por fase em
`GET /api/questions` e fica em cache no cliente. O poll de 1s carrega só o
estado (~1,9 KB), o que mantém 150 celulares em ~285 KB/s no total.

Os formatos de resposta da API são o contrato. Trocar polling por WebSocket no
futuro significa publicar exatamente os mesmos payloads — nenhum componente Vue
precisa mudar.

**Stack:** Laravel 13 · PHP 8.4 · PostgreSQL · Vue 3 · Vite · Pinia · Vue Router · TailwindCSS 4

## Rodando localmente

```bash
docker run -d --name roc-pgsql -e POSTGRES_DB=roc -e POSTGRES_USER=roc \
    -e POSTGRES_PASSWORD=secret -p 5436:5432 postgres:18-alpine

cp .env.example .env          # ajuste DB_* e MASTER_TOKEN
php artisan key:generate
composer install && npm install

php artisan migrate --seed    # evento, 20 mesas e os 2 blocos de 20 perguntas
composer run dev              # servidor + vite
```

Acesse `http://localhost:8000`. O painel master pede o `MASTER_TOKEN` do `.env`
(ou abra `/master?token=SEU_TOKEN` uma vez — o token fica salvo no navegador).

### Ensaiando com a sala cheia

```bash
php artisan live:demo --participants=150              # 150 pessoas nas mesas, com missão sorteada
php artisan live:demo --participants=0 --progress=80  # 80% da rodada atual já votou
```

O `--progress` respeita a rodada corrente e embute o viés da missão, para o
placar da virada de fase ficar legível no ensaio.

## Roteiro do evento

| Passo | Botão no painel | O que acontece |
|---|---|---|
| 1 | **Abrir Evento** | participantes entram, escolhem a mesa e recebem a missão |
| 2 | **▶ Abrir votação da rodada** | cronômetro corre, votos são aceitos |
| 3 | **⏹ Encerrar votação** | (opcional) fecha antes do tempo |
| 4 | **📊 REVELAR no telão** | consequência, pontos e distribuição vão ao telão |
| 5 | **⏭ Próxima rodada** | carrega a rodada seguinte |
| 6 | **🎭 Revelar placar por missão** | a virada de fase |
| 7 | **➡ Ir para a Fase 2** | rodada de consenso por mesa |
| 8 | **🎲 Rodada de desempate** | só se o empate sobreviver aos 3 primeiros critérios |
| 9 | **🏁 Finalizar evento** | telão mostra a mesa vencedora |

`+10s` / `+30s` estendem a rodada, e *Zerar votos da rodada* existe como saída
de emergência.

## Regras de negócio

- **Nada de gabarito antes da revelação.** Durante a votação, os pontos e os
  efeitos das alternativas **não são serializados** — o telão mostra só quantos
  votos chegaram. Não adianta abrir o DevTools. A nota de viés de cada rodada
  existe só no `/api/admin/overview`.
- **Pontos congelados no voto.** Cada linha de voto guarda os pontos da
  alternativa escolhida, então corrigir a régua no meio do evento não reescreve
  o placar já formado.
- **Voto único garantido pelo banco**: índice único `(participant_id,
  question_id)` na Fase 1 e `(event_table_id, question_id)` na Fase 2.
- **Missões distribuídas de forma circular**, não por sorteio puro: com 4
  missões e ~150 pessoas, o aleatório puro deixaria grupos de tamanhos bem
  diferentes e o placar por missão ficaria difícil de comparar. Quem chega
  atrasado entra no menor grupo.
- **O placar por missão compara médias por voto**, não somas — senão o grupo
  maior venceria por tamanho, não por decisão.
- **Cronômetro no servidor.** Zerou, os votos são recusados mesmo antes de o
  facilitador encerrar.
- **Cadastro em uma tela**: nome, e-mail (único por evento), avatar montado e
  mesa. O e-mail fica em `$hidden` e nunca sai para o telão.

### Critério de vitória (4 níveis)

1. maior **Valor Gerado Total** (Fase 1 + Fase 2)
2. maior pontuação na **Fase 2**
3. maior **evolução Fase 1 → Fase 2**
4. **rodada de desempate ao vivo**

A evolução compara a média de pontos **por rodada**: quanto a decisão conjunta
rendeu frente ao que os membros vinham rendendo sozinhos. O painel marca em
vermelho quem chegou ao nível 4 ainda empatado com a liderança — é o gatilho
para o facilitador rodar a pergunta bônus.

### Estados da mesa

| Cor | Estado | Significado |
|---|---|---|
| Cinza | `idle` | fase não iniciada |
| Azul | `discussing` | respondendo |
| Amarelo | `warning` | último quarto do tempo |
| Verde | `done` | bloco completo (todos da mesa na Fase 1, a mesa na Fase 2) |
| Vermelho | `offline` | ninguém da mesa conectado |

## API

| Método | Rota | Uso |
|---|---|---|
| `GET` | `/api/bootstrap` | evento + mesas para a tela de entrada |
| `POST` | `/api/join` | cadastra o participante (nome, e-mail, mesa), devolve o token do dispositivo |
| `GET` | `/api/status` | **poll de 1s** do participante |
| `GET` | `/api/timer` | cronômetro isolado |
| `POST` | `/api/vote` | registra a decisão da rodada (`option_id`) |
| `POST` | `/api/claim-representative` | assume o posto de representante da mesa |
| `GET` | `/api/display` | **poll de 1s** do telão (inclui o mapa das mesas) |
| `GET` | `/api/admin/overview` | **poll de 1s** do painel master |
| `POST` | `/api/admin/{open,start,close,reveal,next,end}` | controle da rodada |
| `POST` | `/api/admin/missions/{assign,reveal,hide}` | sorteio e virada de fase |
| `POST` | `/api/admin/{next-phase,bonus-round}` | fase 2 e desempate |
| `POST` | `/api/admin/{add-time,reset-round,layout}` | tempo extra, reset e layout |
| `POST` | `/api/admin/tables/{id}/representative` | designa o representante da mesa |
| `GET/POST/PATCH/DELETE` | `/api/admin/tables/…` | CRUD das mesas |

Autenticação: participantes usam o header `X-Participant-Token` recebido no
`/join`; o painel usa `X-Master-Token` (segredo único, sem tela de login para
não atrapalhar quem está no palco).

## Deploy (DigitalOcean Ubuntu 24.04)

```bash
git clone <repo> && cd roc
cp .env.example .env          # APP_ENV=production, APP_DEBUG=false, MASTER_TOKEN forte
docker compose up -d --build
docker compose exec app php artisan migrate --seed --force
docker compose exec app php artisan config:cache route:cache view:cache
```

`docker-compose.yml` sobe três serviços: `nginx` (assets + proxy), `app`
(php-fpm com OPcache) e `pgsql`. A imagem do nginx é construída a partir do
mesmo estágio PHP, então os assets compilados nunca ficam dessincronizados.

## Testes

```bash
php artisan test
```

Cobrem o sigilo do gabarito até a revelação, o congelamento dos pontos no voto,
a distribuição equilibrada das missões, o placar por missão revelando o viés, o
travamento do representante na Fase 2, o critério de vitória em 4 níveis com a
detecção de empate na liderança, e a travessia das 5 rodadas até a Fase 2.

## Conteúdo do evento

Missões, rodadas, alternativas e pontos ficam em
`database/seeders/LiveConsensusSeeder.php`. Trocar o conteúdo é editar esse
arquivo — nada no código depende do texto.

> ⚠️ **Pendente:** a apresentação não define o cenário da **Fase 2** nem a
> **pergunta bônus de desempate**. As duas estão no seeder como
> `[PREENCHER]`, com a mecânica pronta e a régua de pontos já aplicada.

## Estendendo

O fluxo do evento vive inteiro em `app/Services/EventFlowService.php` e as
leituras em `EventStateService.php`. Novas dinâmicas (quiz, enquete,
brainstorm, ranking) entram como um novo `mode` de `Question` + uma tela Vue,
reaproveitando mesas, participantes, cronômetro e o mapa do auditório.
