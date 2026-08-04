# Sala de Decisões ROC — especificação extraída da apresentação

Fontes: `Sala de Decisao ROC Apresentacao.pdf` (19 slides), apresentação para a
organização do evento, e o PDF **“Perguntas, Alternativas e Pontuação —
Referência para Cadastro”**, que fixa o texto exato de cada rodada e manda na
seção 4 em caso de divergência. Este documento é a referência de conteúdo e de
regras; o `README.md` descreve o sistema como implementado.

> Frase-guia: *“Se eu retirasse o nome das missões e mostrasse só os números do
> hotel, você ainda tomaria a mesma decisão?”*

## 1. Objetivo da dinâmica

Transformar o auditório em um Comitê Comercial real, decidindo sob pressão, com
dados incompletos e metas conflitantes. O sistema precisa **provar com números**
que decidir isolado custa caro:

1. evidenciar a divergência individual;
2. provar que a decisão conjunta (Fase 2) supera a soma das decisões isoladas
   (Fase 1);
3. deixar um vencedor indiscutível, com critério anunciado antes de começar.

## 2. Missões (novo conceito — não existe no sistema hoje)

Atribuídas **por sorteio dentro de cada mesa** no início da Fase 1 e **fixas nas
5 rodadas**. Não têm relação com o cargo real da pessoa.

| Missão | Enunciado |
|---|---|
| Diária Média | “Seu diretor financeiro pediu que você preservasse a diária média.” |
| Participação de Mercado | “O hotel precisa recuperar participação de mercado.” |
| Reservas Diretas | “A diretoria quer reduzir a dependência de comissão de OTAs.” |
| Ocupação | “O proprietário quer ocupação plena no período.” |

A missão cria o viés: a pessoa tende à opção que serve à *sua* missão, mesmo
quando não é a melhor para o hotel. O placar **por grupo de missão** é o “ahá”
da virada de fase.

**Distribuição — por mesa, em rodízio sorteado.** As 4 missões circulam dentro
de cada mesa: numa mesa de 10, os 4 primeiros integrantes recebem missões
diferentes, os 4 seguintes repetem o ciclo e a sobra de 2 pega duas quaisquer.
A ordem é sorteada, então o padrão não fica visível para quem está sentado ali.
Sortear pela sala inteira poderia juntar uma mesa toda na mesma missão — e é
justamente o conflito **dentro da mesa** que a rodada final precisa resolver.

## 3. Estrutura

### Fase 1 — individual
- Acesso pelo celular com **código pessoal**; ninguém vê o voto de ninguém.
- **5 rodadas**, cada uma com a missão fixa da pessoa.
- Telão mostra apenas o **resultado agregado de todos** — sem recorte por missão.
- Cada voto gera **pontos individuais**, na mesma régua em todas as rodadas.

### Virada de fase
- Revelação do **placar por grupo de missão** — o momento-chave.

### O comparativo (implementado, não previsto no PDF)
- Um segundo momento deliberado do facilitador, depois da rodada final: o telão
  abre o **gabarito** das cinco rodadas da Fase 1, a **pontuação da rodada
  final** mesa a mesa e o **valor gerado por decisão** nos dois formatos.
- A Fase 1 tem régua e por isso tem acerto em percentual; a rodada final é uma
  missão aberta, pontuada à mão. O que compara as duas é valor por decisão —
  sozinho contra junto. É a leitura possível do objetivo 2 lá do começo deste
  documento, que o PDF enuncia mas não diz como medir.

### Fase 2 — mesa
- Acesso por **código de mesa**; decisão por **consenso**.
- Todas as missões se fundem em uma só: **maximizar o resultado total do hotel**.
- **1 rodada** de decisão conjunta, **sem alternativas fixas**.
- A pontuação é **lançada mesa a mesa pelo facilitador**, no painel — não sai de
  uma régua por alternativa.
- Gera **pontuação de mesa**, separada da soma individual dos membros.

### Roteiro (~30 min)

| Etapa | Tempo | Observação |
|---|---|---|
| Abertura e contexto | 1 min | facilitador apresenta o cenário do hotel |
| Fase 1 — 5 rodadas individuais | 6 min | **30s de votação + ~20s de revelação por rodada** |
| Virada de fase (revelação por grupo de missão) | 2 min | o viés aparece no placar |
| Fase 2 — rodada final por mesa | 6 min | **~5 min de consenso + lançamento da pontuação** |
| **O comparativo** (gabarito + rodada final) | 3 min | a prova numérica do objetivo 2 |
| Placar final e desempate | 2 min | critério em 4 níveis |
| Mensagem de fechamento | 3 min | |

Total ~23 min + margem. O roteiro pressupõe facilitador experiente.

> Para ajustar os relógios antes de semear: `LiveConsensusSeeder::PHASE_ONE_DURATION`
> (padrão 30s, cada rodada da Fase 1) e `LiveConsensusSeeder::FINAL_ROUND_DURATION`
> (padrão 300s). Ao vivo, **⏹ Encerrar** corta a rodada a qualquer momento e
> **+10s / +30s** a estica. Dentro da rodada aberta, cada pessoa pode trocar a
> alternativa quantas vezes quiser — vale a última.

## 4. Pontuação (novo — não existe no sistema hoje)

Cada alternativa vale pontos fixos. Régua usada em todas as rodadas:
**+150 (melhor) · +80 · 0 · −50**.

### Rodada 1 — TARIFA & OCUPAÇÃO
> Terça-feira, faltam 9 dias para o feriado. Forecast: ocupação 68% (meta 85%).
> Dois concorrentes diretos já anunciaram promoções para o mesmo período, e o
> ritmo de reservas está 12% abaixo do mesmo feriado do ano passado.

| Opção | Efeito | Pontos |
|---|---|---|
| A) Reduzir tarifa 10% em todos os canais | Aumenta o pickup em cerca de 15 quartos. O desconto também se aplica a reservas que ocorreriam mesmo sem ele. | +80 |
| B) Tarifa relâmpago só no canal direto (48h) | Aumenta o pickup em volume semelhante à opção A. A tarifa cheia é mantida nos demais canais. | **+150** |
| C) Manter tarifa + estadia mínima de 2 noites | Preserva a diária média nominal. Reduz a demanda elegível, já que parte do público busca apenas 1 noite. | −50 |
| D) Esperar 48h monitorando o pickup | Mantém tarifa e inventário intactos. Adia a resposta num mercado onde os concorrentes já se movem. | 0 |

*Viés:* missão Diária Média tende à C; Participação de Mercado tende à A.

### Rodada 2 — GRUPO & CONGRESSO
> Congresso de 3 dias confirmado na cidade — demanda potencial de ~40 quartos.
> O hotel já tem 12 quartos de grupo confirmados para a mesma data, 10% abaixo
> da meta de diária.

| Opção | Efeito | Pontos |
|---|---|---|
| A) Subir a tarifa geral imediatamente | Aumenta a diária média nas vendas ainda abertas. Não se aplica ao bloco de grupo já confirmado. | +80 |
| B) Fechar o canal OTA por 48h | Redireciona parte da demanda para o direto. Reduz o alcance total num pico de buscas pela cidade. | −50 |
| C) Criar tarifa corporativa fixa para o congresso, com mínimo de diárias | Organiza a demanda do evento em bloco previsível, separado do inventário individual. | **+150** |
| D) Esperar a demanda se confirmar sozinha | Não altera nada agora. Agências de congresso fecham bloqueios com antecedência. | 0 |

*Viés:* Reservas Diretas tende à B; Diária Média tende à C.

### Rodada 3 — INVESTIMENTO EM MARKETING
> A performance de mídia paga está 15% abaixo da meta mensal, e a diretoria
> pediu um resultado visível em 48h. Orçamento aprovado para apenas 1 campanha.

| Opção | Efeito | Pontos |
|---|---|---|
| A) Google Ads segmentado para o período | Captura quem já pesquisa o destino. Não amplia a demanda de quem ainda não considerou viajar. | +80 |
| B) Busca de marca (branded search) reforçando o site oficial do hotel | Reforça a visibilidade de quem já pretende reservar direto. Não amplia a demanda de quem ainda não considerou o hotel — o problema é de alcance, não de marca. | −50 |
| C) Parceria com influenciadores regionais | Alta exposição de marca. Custo fixo, independente das reservas geradas. | −50 |
| D) E-mail/CRM para hóspedes anteriores | Base menor, já convertida. Menor custo por contato e ciclo mais curto. | **+150** |

*Viés:* Reservas Diretas tende à B ou C; Diária Média/Ocupação tendem à D.

### Rodada 4 — NEGOCIAÇÃO DE GRUPOS
> Hotel de 220 apartamentos. Grupo de 60 quartos solicitado a R$ 520 (meta de
> diária média R$ 650). Forecast individual (reservas em carteira) indica 71%
> de ocupação na data —
> restam 64 quartos livres, ritmo forte nos últimos 10 dias e diária média
> projetada de R$ 670 para a demanda individual remanescente.

| Opção | Efeito | Pontos |
|---|---|---|
| A) Aceitar tarifa integral | Fecha o grupo já. Desloca demanda individual que tende a pagar mais na mesma data. | −50 |
| B) Negar o pedido | Preserva a diária de tabela. Depende do forecast individual se converter integralmente. | 0 |
| C) Negociar R$ 580 + mínimo de 3 noites (grupo cai para 45 quartos) | Aumenta a receita por quarto do grupo. Reduz volume garantido, com risco de recusa. | +80 |
| D) Negociar R$ 520 + consumo mínimo de F&B obrigatório | Mantém os 60 quartos. Compensa a tarifa com receita de A&B por quarto ocupado. | **+150** |

*Viés:* Participação de Mercado/Ocupação tendem à A; Diária Média tende à D.

### Rodada 5 — DISTRIBUIÇÃO & MÍDIA PAGA
> O canal direto responde por 22% das reservas, abaixo da meta de 30%. O Google
> Hotel Ads oferece posição de destaque por 30 dias mediante aumento de 40% no
> CPC.

| Opção | Efeito | Pontos |
|---|---|---|
| A) Aceitar o aumento de CPC integral | Visibilidade imediata. O CPC maior incide sobre todos os cliques, não só os que convertem. | −50 |
| B) Recusar o aumento | Custo estável. Se concorrentes aceitarem, a posição pode cair sem mudança de comportamento. | 0 |
| C) Aceitar por só 10 dias, no período de menor demanda | Concentra o investimento onde a visibilidade é mais necessária. | +80 |
| D) Negociar CPC diferenciado, maior só em conversões incrementais | Direciona o custo extra ao resultado que ele gera. Exige rastreamento mais sofisticado. | **+150** |

*Viés:* Ocupação tende à A; Diária Média tende à D.

### Rodada final — Fase 2 (mesa)
Diferente das rodadas 1 a 5, a rodada final **não é uma escolha entre 4
alternativas fixas**. Todas as mesas recebem a mesma missão final — a que une as
4 missões individuais da Fase 1 em um único objetivo — e decidem livremente por
consenso.

> **Texto da missão final (igual para todas as mesas):**
> *“Maximizar o resultado total do hotel — pensando ao mesmo tempo em diária
> média, ocupação, reservas diretas e participação de mercado, como o Comitê
> Comercial completo.”*

A pontuação dessa rodada é **lançada manualmente pelo facilitador no painel**,
mesa a mesa, e não calculada por alternativa. No sistema, a pergunta tem
`manual_scoring = true` e o lançamento grava uma linha em `table_votes` sem
`option_id` — soma no placar da Fase 2 como qualquer decisão de mesa, mas nunca
conta como acerto: não havia régua para acertar.

### Desempate — rodada bônus
Pergunta bônus única, decidida por consenso da mesa em 60 segundos. **O PDF não
especifica o conteúdo dessa pergunta.**

## 5. Critério de vitória (4 níveis, anunciado antes de começar)

1. **Maior Valor Gerado Total** — pontuação Fase 1 (individual) + Fase 2 (mesa)
2. **Maior pontuação na Fase 2** — em caso de empate no total
3. **Maior evolução Fase 1 → Fase 2** — premia quem mais aprendeu em tempo real
4. **Rodada de desempate ao vivo** — pergunta bônus, consenso da mesa, 60s

> **Decisão de implementação — diverge do PDF.** O PDF pede “placar sempre
> visível no telão em tempo real”. Pontuação ao vivo é gabarito ao vivo: numa
> mesa pequena, o total da Fase 1 depois da rodada 1 identifica a alternativa
> certa por aritmética — e saber a régua da rodada 1 muda como a sala joga as
> quatro seguintes. O telão mostra a **distribuição dos votos** a cada revelação
> e o **placar completo com o gabarito** no fecho. O facilitador vê tudo o tempo
> todo no painel.

## 6. As três telas

| Tela | Papel |
|---|---|
| Celular | Fase 1: código pessoal, voto individual e sigiloso. Fase 2: a missão final da mesa — sem alternativa a marcar, quem pontua é o facilitador. |
| Telão | Pergunta, tempo, votos chegando e — **só após o clique do facilitador** — a distribuição dos votos. O gabarito e o placar, só no encerramento. |
| Painel Master | Senha do facilitador. Controla rodadas, revela resultados e ajusta o placar em tempo real. |

Sem app: acesso por link ou QR code nas duas fases.

### Painel Master — três camadas de placar (coexistem)

1. **Total Geral** — ranking individual de todos, somando as 5 rodadas da Fase 1.
2. **Total por Grupo de Missão** — soma/média de quem jogou com a mesma missão;
   revela o viés. É o que se mostra na virada de fase.
3. **Total por Mesa** — só após a virada: soma dos membros (Fase 1) + a rodada
   final (Fase 2) + evolução % — usada no critério de vitória.

## 7. Mensagem de fechamento

> “Os hotéis mais rentáveis do futuro não serão os que tiverem a melhor Vendas,
> o melhor Marketing, o melhor Revenue Management ou a melhor Distribuição.
> Serão os que conseguirem fazer Vendas, Marketing, Revenue Management e
> Distribuição decidirem como uma área só.”

---

## 8. Distância entre o PDF e o sistema atual

| Tema | PDF | Sistema hoje |
|---|---|---|
| Fase 1 | **5 rodadas sincronizadas**, ~20s de voto + 20s de revelação cada | 20 perguntas no ritmo de cada um, cronômetro global |
| Fase 2 | **1 rodada** por mesa | 20 perguntas por mesa |
| Missões | 4 missões sorteadas, fixas, geram o viés | **não existe** |
| Pontos | +150 / +80 / 0 / −50 por alternativa | **não existe** — só distribuição de votos |
| Placar | 3 camadas + critério de vitória em 4 níveis | **não existe** |
| Revelação | manual, a cada rodada, pelo facilitador | resultados só ao fim da fase |
| Acesso Fase 2 | código de mesa | representante da mesa |
| Conteúdo | 5 cenários reais de hotelaria | 40 perguntas genéricas de exemplo |

O que já serve sem mudança: mesas com identidade e mapa do auditório, avatares,
presença/heartbeat, cronômetro no servidor, polling de 1s, painel master com
token, editor de layout, QR code e toda a camada de deploy.
