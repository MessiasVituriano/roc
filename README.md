# Sistema IO — Sala de Decisões ROC

Dinâmica de decisão para auditórios, conduzida ao vivo por um facilitador.
Especificação de conteúdo e regras: [`docs/dinamica-roc.md`](docs/dinamica-roc.md).

- **Fase 1 — individual.** 5 rodadas sincronizadas de 90s.
  Cada pessoa recebe uma **missão sorteada** e decide com ela, e pode trocar a
  escolha enquanto o cronômetro corre.
- **Virada de fase.** O placar **por grupo de missão** vai ao telão: mesma
  régua, missões diferentes, decisões diferentes.
- **Fase 2 — em mesa.** As **mesmas cinco perguntas**, agora decididas por
  consenso pela mesa (90s cada), com o **representante** registrando por
  todos. Fecha com a **rodada final**: uma 6ª pergunta **sem alternativas** —
  a missão que funde as 4 missões da Fase 1 —, **lançada mesa a mesa pelo
  facilitador** no painel.
- **O comparativo.** O fecho: o gabarito das cinco rodadas e o confronto
  direto — **a mesma pergunta**, decidida sozinho e depois em mesa, em acerto e
  em **valor gerado por decisão**.

Cada alternativa vale pontos fixos (**+150 / +80 / 0 / −50**) e nada disso
aparece antes do **clique do facilitador**. A régua é a mesma nas duas fases —
é o que torna o confronto uma medida. Só a rodada final não tem régua: quem
pontua é o facilitador.

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
| 5 | **⏭ Próxima rodada** | carrega a rodada seguinte — revelar não é pré-requisito |
| ↩ | **⏮ Rodada anterior** | volta uma rodada (revelada, se já foi jogada); na 1ª rodada da fase, volta a fase |
| 6 | **🎭 Revelar placar por missão** | a virada de fase |
| 7 | **➡ Ir para a Fase 2** | carrega a 1ª das cinco rodadas de mesa; da 6ª em diante a pontuação é lançada à mão no painel |
| 8 | **🔓 Revelar gabarito + comparativo** | **o fecho:** gabarito da Fase 1 e a rodada final no telão |
| 9 | **🎲 Rodada de desempate** | só se o empate sobreviver aos 3 primeiros critérios |
| 10 | **🏁 Finalizar evento** | telão mostra a mesa vencedora |

Os passos **6** e **8** são os dois momentos deliberados do facilitador — o
telão só muda quando ele clica. O passo 8 é separado do 10 de propósito:
encerrar joga os celulares na tela de "obrigado", enquanto revelar o gabarito
mantém a sala inteira olhando para o telão.

**Avançar e voltar são livres dentro do roteiro.** O passo 4 é o caminho
normal, não uma condição: **⏭** segue com a rodada parada (pular uma rodada que
não vai ser jogada) ou com a votação fechada (a conversa já resolveu antes do
telão) — e aí o botão se anuncia como *(sem revelar)*, sem o destaque que ele
tem depois da revelação. Nenhum dos dois apaga voto: os votos moram na pergunta,
então **⏮** traz a rodada de volta — **revelada**, se ela chegou a ser jogada.

Os dois ficam travados enquanto a votação está **aberta**: um clique torto não
derruba a rodada que está correndo. **⏹ Encerrar** primeiro, e aí os dois
liberam.

`+10s` / `+30s` estendem a rodada. **🔄 Recarregar a rodada** é a saída de
emergência: apaga os votos da rodada corrente e devolve o evento ao ponto de
abrir a votação, sem encostar nas outras rodadas — para quando a pergunta subiu
ao telão antes da hora ou a sala votou no meio de uma explicação.

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
  question_id)` na Fase 1 e `(event_table_id, question_id)` na Fase 2. Votar de
  novo **troca** a escolha na mesma linha (e recongela os pontos): mudar de
  ideia faz parte da decisão, e quem fecha a linha é o fim do tempo — não o
  primeiro toque. Na Fase 2 quem troca é o representante, pela mesa.
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
- **Cadastro em uma tela**: nome, **e-mail ou telefone** (a pessoa escolhe o
  tipo; um dos dois basta e cada um é único por evento), **hotel**, avatar
  montado (**masculino ou feminino**, mais pele, cabelo e roupa) e mesa. O
  sprite neutro continua no renderizador — é o fallback de qualquer `gender`
  desconhecido, como o default da coluna —, mas está fora da escolha, no
  seletor e na validação. O telefone é normalizado para dígitos sem código de país, para
  `+55 (11) 99999-9999` e `11999999999` não virarem duas pessoas.

  O **hotel** aparece nas duas listagens do painel — ranking individual e gaveta
  da mesa —, porque é o que distingue dois “Ana S.” numa sala de 150. O
  **contato** não: fica em `$hidden`, fora do poll de 1s, e só sai por
  `GET /api/admin/tables/{id}` e por `GET /api/me` — o painel do facilitador e o
  celular do próprio dono —, os dois buscados sob demanda. Nenhum dos dois vai
  ao telão.
- **Corrigir o cadastro, só antes de abrir.** O cadastro é feito em pé, num
  celular, no auditório: o nome sai torto, sai só o primeiro nome, o hotel sai
  errado. Enquanto o evento está em rascunho, a sala de espera tem **✏️ Editar
  meus dados** — nome, contato, hotel, sexo e avatar.

  Depois de aberto, o servidor recusa (409). O nome já está no telão e no
  ranking, o avatar já é como a mesa reconhece a pessoa e a missão já foi
  sorteada — deixar isso mudar no meio transformaria o placar numa coisa que a
  sala não consegue acompanhar. A **mesa** continua trocável, porque ali o que
  muda é onde a pessoa senta, não quem ela é.

  As duas telas montam os mesmos campos a partir dos mesmos componentes
  (`ContactField`, `AvatarBuilder`) e das mesmas regras (`lib/contact.js`,
  `profileRules()`): a regra do contato — e-mail **ou** telefone, único por
  evento — é sutil o bastante para que duas cópias divirjam sem ninguém
  perceber.
- **Dez pessoas por mesa** (`EventTable::MAX_PARTICIPANTS`). É o tamanho que o
  rodízio de missões pressupõe — quatro missões nos quatro primeiros, o ciclo
  repetido nos quatro seguintes, sobra de dois — e o teto da conversa: consenso
  de doze em dois minutos não acontece, vira a opinião dos dois mais falantes.

  A mesa cheia continua na lista do cadastro, desabilitada e marcada como
  *completa* — sumir com ela faria a pessoa procurar uma mesa que está vendo na
  sala. A contagem roda **dentro de uma transação com a linha da mesa travada**:
  sem isso, os celulares que tocam "Entrar" no mesmo segundo passariam todos
  pela verificação antes de qualquer um gravar, e o teto viraria decoração
  justamente na corrida de entrada.
- **Trocar de mesa depois de entrar.** Sentou na errada, o colega estava na
  outra, a mesa do cadastro encheu antes de ele chegar nela: a tela do
  participante troca pelo `POST /api/change-table`, com a mesma trava de
  lotação da entrada.

  Os **votos já dados não se movem**. Cada linha guarda a mesa em que a decisão
  foi tomada, e reescrevê-la mudaria o placar de duas mesas por causa de uma
  troca de cadeira — quem muda no meio do evento contribuiu de verdade para as
  duas, nas rodadas que jogou em cada uma. Sair da mesa **devolve o posto de
  representante**, que é da mesa e não da pessoa; é por isso que a troca é
  recusada com a votação aberta, quando a saída levaria a decisão da mesa
  junto. A **missão** é resorteada só para quem ainda não votou, pelo mesmo
  critério de quem chega atrasado: depois do primeiro voto ela fica, porque
  está congelada em cada linha e trocá-la mudaria a pergunta que a pessoa vinha
  respondendo.
- **Bloqueio no ranking individual.** O facilitador pode tirar alguém do pódio
  (🚫 na gaveta da mesa ou na aba **Individual**) sem tirá-lo da dinâmica: os
  votos continuam contando para a mesa e para o grupo de missão, e o celular da
  pessoa não muda de comportamento. É a saída para o nome impróprio, o cadastro
  duplicado e quem está na sala ajudando a conduzir — apagar o voto reescreveria
  o critério de vitória da mesa por causa de um problema de vitrine.

  A **única** outra coisa que o bloqueio muda é o posto de representante: quem
  está bloqueado não assume a mesa (o botão do painel some, o `exists` da API
  recusa, e bloquear alguém que já era representante libera o posto). O posto é
  a única forma de uma pessoa aparecer *falando pela mesa*, que é justamente o
  que o bloqueio evita. E é invisível do outro lado: a tela dele é a de quem não
  registra pela mesa — a mesma de qualquer colega —, e o `POST
  /api/claim-representative` responde `claimed: false`, indistinguível de quem
  chegou em segundo no botão.

### Critério de vitória (4 níveis)

1. maior **Valor Gerado Total** (Fase 1 + Fase 2)
2. maior pontuação na **Fase 2**
3. maior **evolução Fase 1 → Fase 2**
4. **rodada de desempate ao vivo**

A evolução compara a média de pontos **por decisão** nas mesmas cinco
perguntas: quanto a mesa rendeu frente ao que os membros vinham rendendo
sozinhos. O painel marca em vermelho quem chegou ao nível 4 ainda empatado com
a liderança — é o gatilho para o facilitador rodar a pergunta bônus.

**Onde a rodada final entra e onde não entra.** Ela soma nos **pontos** e no
total, como qualquer decisão. Fica fora das **médias**, da **evolução** e do
**acerto**: é a única pergunta sem alternativa, pontuada numa escala que é do
facilitador, e misturá-la faria a comparação medir duas réguas ao mesmo tempo —
um lançamento generoso viraria "evolução". Por isso a coluna de acertos da Fase
2 conta 5 decisões, não 6.

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
| `POST` | `/api/join` | cadastra o participante (nome, `email` **ou** `phone`, `hotel`, mesa), devolve o token do dispositivo |
| `GET` | `/api/status` | **poll de 1s** do participante |
| `GET` | `/api/timer` | cronômetro isolado |
| `POST` | `/api/vote` | registra a decisão da rodada (`option_id`) |
| `POST` | `/api/claim-representative` | assume o posto de representante da mesa |
| `POST` | `/api/change-table` | troca de mesa (`table_id`) |
| `GET` | `/api/me` | os próprios dados, **com o contato**, para o formulário de edição |
| `POST` | `/api/update-profile` | corrige o próprio cadastro (só com o evento em rascunho) |
| `GET` | `/api/display` | **poll de 1s** do telão (inclui o mapa das mesas) |
| `GET` | `/api/admin/overview` | **poll de 1s** do painel master |
| `POST` | `/api/admin/{open,start,close,reveal,next,previous,end}` | controle da rodada |
| `POST` | `/api/admin/missions/{assign,reveal,hide}` | sorteio e virada de fase |
| `POST` | `/api/admin/{next-phase,bonus-round}` | fase 2 e desempate |
| `POST` | `/api/admin/final-score` | lança a pontuação da rodada final de uma mesa (`points: null` apaga) |
| `POST` | `/api/admin/{add-time,reset-round,layout}` | tempo extra, reset e layout |
| `POST` | `/api/admin/tables/{id}/representative` | designa o representante da mesa |
| `POST` | `/api/admin/participants/{id}/block` | `{"blocked": true\|false}` — tira do ranking individual sem tirar da dinâmica |
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

Os **cinco cenários** vivem em `LiveConsensusSeeder::scenarios()`, sem fase —
o seeder os monta duas vezes, individuais na Fase 1 e em consenso na Fase 2.
Editar um cenário muda as duas pontas de uma vez, que é o que mantém o
comparativo honesto.

A **rodada final** (Fase 2, rodada 6) e o **desempate** (rodada 7) ficam em
`closingRounds()`. A final tem `manual_scoring => true` e `options => []`: o
texto da missão vive em `context`, a duração em
`LiveConsensusSeeder::PHASE_TWO_DURATION` e a pontuação entra pelo painel,
mesa a mesa.

> ⚠️ **Pendente:** a apresentação não define a **pergunta bônus de desempate**.
> Ela está no seeder como `[PREENCHER]` (Fase 2, rodada 2, `is_bonus`), com a
> mecânica pronta e a régua de pontos já aplicada.

## Estendendo

O fluxo do evento vive inteiro em `app/Services/EventFlowService.php` e as
leituras em `EventStateService.php`. Novas dinâmicas (quiz, enquete,
brainstorm, ranking) entram como um novo `mode` de `Question` + uma tela Vue,
reaproveitando mesas, participantes, cronômetro e o mapa do auditório.
