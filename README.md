# Sistema IO — Sala de Decisões ROC

Dinâmica de decisão para auditórios, conduzida ao vivo por um facilitador.
Especificação de conteúdo e regras: [`docs/dinamica-roc.md`](docs/dinamica-roc.md).

- **Fase 1 — individual.** 5 rodadas sincronizadas (~20s de voto + revelação).
  Cada pessoa recebe uma **missão sorteada** e decide com ela.
- **Virada de fase.** O placar **por grupo de missão** vai ao telão: mesma
  régua, missões diferentes, decisões diferentes.
- **Fase 2 — a rodada final.** Uma rodada só, **sem alternativas**: todas as
  mesas recebem a mesma missão final — a que funde as 4 missões da Fase 1 — e
  decidem livremente por consenso (~5 min). A pontuação é **lançada mesa a mesa
  pelo facilitador**, no painel.
- **O comparativo.** O fecho: o gabarito das cinco rodadas da Fase 1, a
  pontuação da rodada final por mesa e o **valor gerado por decisão** nos dois
  formatos — sozinho contra em mesa.

Nas cinco rodadas da Fase 1 cada alternativa vale pontos fixos
(**+150 / +80 / 0 / −50**) e nada disso aparece antes do **clique do
facilitador**. A rodada final não tem régua: quem pontua é o facilitador.

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

## Subindo em produção

Tudo que o evento precisa está no `docker-compose.yml`: Caddy (TLS) → nginx →
php-fpm → Postgres. Uma VPS de **2 vCPU / 4 GB** dá conta com folga.

```bash
git clone <repo> roc && cd roc
cp .env.example .env
```

No `.env`, o que muda em relação ao local:

```ini
APP_ENV=production
APP_DEBUG=false
APP_KEY=              # gere com o comando abaixo
APP_URL=https://SEU-ENDERECO
APP_DOMAIN=SEU-ENDERECO   # sem domínio? use 203-0-113-10.sslip.io
DB_PASSWORD=<senha-forte>
MASTER_TOKEN=<token-forte>   # quem tiver isso conduz o evento
```

`APP_KEY` sem precisar de PHP na máquina:

```bash
docker run --rm php:8.4-cli-alpine php -r \
    'echo "base64:".base64_encode(random_bytes(32))."\n";'
```

Suba e semeie:

```bash
docker compose up -d --build
docker compose exec app php artisan db:seed --force
```

O [entrypoint](docker/php/entrypoint.sh) roda `migrate --force` sozinho a cada
boot e, com `APP_ENV != local`, faz `config:cache`, `route:cache` e
`view:cache`. **O seed é o único passo manual** — ele cria o evento, as 20 mesas
e os 2 blocos de perguntas, e rodar de novo duplicaria tudo.

Health check em `/up`. Logs: `docker compose logs -f app`.

### Atualizando depois da primeira subida

```bash
./scripts/deploy.sh              # pull, build, troca os containers, verifica
./scripts/deploy.sh --no-pull    # usa o código já no disco (rollback manual)
./scripts/deploy.sh --force      # ignora a trava de evento ao vivo
```

O script recusa o deploy se o `.env` estiver incompleto (sem `APP_KEY`, fora de
`production`, com `APP_DEBUG=true`) ou se houver **evento em andamento** —
trocar os containers reinicia o php-fpm, e no meio de uma rodada isso derruba
o cronômetro com a sala olhando para o telão. Antes de trocar qualquer coisa
ele faz um dump do banco em `~/roc-backups`.

Ele nunca roda `db:seed` nem `down -v`: o primeiro duplicaria evento, mesas e
perguntas; o segundo apagaria o volume do Postgres. As migrations não estão
lá porque o [entrypoint](docker/php/entrypoint.sh) já roda `migrate --force`
em todo boot.

### Sobre o TLS

`APP_DOMAIN` é a única chave: com um nome real o Caddy emite e renova o
certificado sozinho; com `:80` ele serve HTTP puro (só para testar a stack).
Não vá para o evento em HTTP — o telão projeta um QR Code, e o aviso de "site
não seguro" aparece justamente na tela onde 150 pessoas digitam nome e e-mail.

Sem domínio próprio, `sslip.io` resolve qualquer IP embutido no nome
(`203-0-113-10.sslip.io` → `203.0.113.10`) e o Let's Encrypt emite certificado
para ele normalmente. Em qualquer um dos casos, as portas 80 e 443 precisam
estar abertas: a validação do certificado entra pela 80.

Nginx e php-fpm não publicam porta — só o Caddy alcança eles. É isso que torna
o `trustProxies(at: '*')` do [bootstrap/app.php](bootstrap/app.php) seguro.

### Dimensionamento

O pool do php-fpm está em [docker/php/zzz-pool.conf](docker/php/zzz-pool.conf)
com 32 workers `static`. O default da imagem oficial é 5, dimensionado para um
site comum — aqui são ~152 req/s constantes (150 celulares + telão + master,
todos com poll de 1s). Medido com `ab -n 1500 -c 150` no `/api/display`:

| Pool | Throughput | p99 |
|---|---|---|
| default da imagem (5) | 285 req/s | 664 ms |
| este arquivo (32) | 403 req/s | 474 ms |

Os 5 workers aguentariam o evento — cada request é ~3ms — mas com 1,9x de folga
num endpoint leve. Os 32 dão 2,6x, e a margem é para os endpoints pesados
(`/api/admin/overview`) e para a rajada de votos na abertura da rodada.

Numa VPS de 2 GB, baixe para 16: ainda é bem mais que o pico real.

## Roteiro do evento

| Passo | Botão no painel | O que acontece |
|---|---|---|
| 1 | **Abrir Evento** | participantes entram, escolhem a mesa e recebem a missão |
| 2 | **▶ Abrir votação da rodada** | cronômetro corre, votos são aceitos |
| 3 | **⏹ Encerrar votação** | (opcional) fecha antes do tempo |
| 4 | **📊 REVELAR no telão** | a distribuição dos votos vai ao telão (sem gabarito) |
| 5 | **⏭ Próxima rodada** | carrega a rodada seguinte |
| 6 | **🎭 Revelar placar por missão** | a virada de fase |
| 7 | **➡ Ir para a Fase 2** | carrega a rodada final; a pontuação é lançada mesa a mesa no painel |
| 8 | **🔓 Revelar gabarito + comparativo** | **o fecho:** gabarito da Fase 1 e a rodada final no telão |
| 9 | **🎲 Rodada de desempate** | só se o empate sobreviver aos 3 primeiros critérios |
| 10 | **🏁 Finalizar evento** | telão mostra a mesa vencedora |

Os passos **6** e **8** são os dois momentos deliberados do facilitador — o
telão só muda quando ele clica. O passo 8 é separado do 10 de propósito:
encerrar joga os celulares na tela de "obrigado", enquanto revelar o gabarito
mantém a sala inteira olhando para o telão.

`+10s` / `+30s` estendem a rodada, e *Zerar votos da rodada* existe como saída
de emergência.

## Regras de negócio

- **Sigilo em duas camadas.** Durante a votação o telão mostra só quantos votos
  chegaram. Na revelação da rodada mostra a **distribuição** — quem votou em quê
  — e nada mais. O **gabarito** (melhor alternativa, pontos, efeitos, cor da
  alternativa, acertos) só sai do servidor quando o facilitador clica em
  **🔓 Revelar gabarito + comparativo** (ou quando o evento é encerrado) — e no
  `/api/admin/overview`, que nunca é projetado.

  A segunda camada existe porque saber a régua da rodada 1 muda como a sala
  joga as quatro seguintes. E o gabarito vaza por mais caminhos do que parece —
  todos fechados e cobertos por teste:

  | Caminho | Por que entrega |
  |---|---|
  | `is_best`, `points`, `effect` | direto |
  | `color` da alternativa | vem de `colorForPoints()` — verde é +150, vermelho é −50 |
  | ordenação da revelação | ordenar por régua põe a melhor sempre no topo |
  | `me.round_points` / `me.total_points` | o total entrega por diferença entre rodadas |
  | `me.correct` | saber que acertou é saber qual era a certa |
  | `table_ranking` | numa mesa pequena, `phase_one_points` é aritmética simples |
  | acertos por missão | com poucas pessoas no grupo, “100% de acerto” identifica a alternativa |

  Na revelação as barras usam uma paleta neutra por posição (A/B/C/D), não a
  cor da régua.
- **Pontos congelados no voto.** Cada linha de voto guarda os pontos da
  alternativa escolhida, então corrigir a régua no meio do evento não reescreve
  o placar já formado.
- **Voto único garantido pelo banco**: índice único `(participant_id,
  question_id)` na Fase 1 e `(event_table_id, question_id)` na Fase 2.
- **Missões em rodízio dentro de cada mesa**, não por sorteio puro. Numa mesa de
  10, os 4 primeiros recebem missões diferentes, os 4 seguintes repetem o ciclo
  e a sobra de 2 pega duas quaisquer — sempre em ordem sorteada. É dentro da
  mesa que a Fase 2 acontece: uma mesa inteira com a mesma missão não teria
  conflito para resolver. Quando duas missões estão igualmente ausentes da mesa,
  a sobra vai para a que tem menos gente **no evento inteiro**, para o placar por
  missão continuar comparável. Quem chega atrasado entra pelo mesmo critério.
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

A evolução compara a média de pontos **por decisão**: quanto a rodada final
rendeu frente ao que os membros vinham rendendo sozinhos. O painel marca em
vermelho quem chegou ao nível 4 ainda empatado com a liderança — é o gatilho
para o facilitador rodar a pergunta bônus.

A rodada final não tem alternativa certa, então a coluna de acertos da Fase 2
aparece como `—`: os pontos dela somam no placar, mas nunca contam como acerto.

### Estados da mesa

| Cor | Estado | Significado |
|---|---|---|
| Cinza | `idle` | fase não iniciada |
| Azul | `discussing` | respondendo |
| Amarelo | `warning` | último quarto do tempo |
| Verde | `done` | bloco completo (todos da mesa na Fase 1, a mesa pontuada na Fase 2) |
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
| `POST` | `/api/admin/final-score` | lança a pontuação da rodada final de uma mesa (`points: null` apaga) |
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

Cobrem o sigilo do gabarito até o encerramento (por todos os caminhos da tabela
acima), a contagem de acertos, o congelamento dos pontos no voto, a distribuição
equilibrada das missões, o placar por missão revelando o viés, o travamento do
representante na rodada de desempate, o critério de vitória em 4 níveis com a
detecção de empate na liderança, e a rodada final: celular sem alternativa,
pontuação lançada, corrigida e apagada pelo painel.

## Conteúdo do evento

Missões, rodadas, alternativas e pontos ficam em
`database/seeders/LiveConsensusSeeder.php`. Trocar o conteúdo é editar esse
arquivo — nada no código depende do texto.

As cinco rodadas da Fase 1 têm alternativas e régua. A **rodada final** (Fase 2,
rodada 1) tem `manual_scoring => true` e `options => []`: o texto da missão vive
em `context`, a duração em `LiveConsensusSeeder::FINAL_ROUND_DURATION` e a
pontuação entra pelo painel, mesa a mesa.

> ⚠️ **Pendente:** a apresentação não define a **pergunta bônus de desempate**.
> Ela está no seeder como `[PREENCHER]` (Fase 2, rodada 2, `is_bonus`), com a
> mecânica pronta e a régua de pontos já aplicada.

## Estendendo

O fluxo do evento vive inteiro em `app/Services/EventFlowService.php` e as
leituras em `EventStateService.php`. Novas dinâmicas (quiz, enquete,
brainstorm, ranking) entram como um novo `mode` de `Question` + uma tela Vue,
reaproveitando mesas, participantes, cronômetro e o mapa do auditório.
