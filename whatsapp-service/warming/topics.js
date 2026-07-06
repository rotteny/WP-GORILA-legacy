/**
 * warming/topics.js — CONTEÚDO CURADO do catálogo de warming.
 *
 * É AQUI que se edita o catálogo. Depois de mexer, rode:
 *     node warming/generate.js
 * pra regravar warming/scripts.json (a saída estática que o engine consome).
 *
 * ┌── REGRA DE OURO ───────────────────────────────────────────────────────────┐
 * │ Só cotidiano genérico. NUNCA produto, cliente, operação, ACCA ou trabalho.  │
 * └─────────────────────────────────────────────────────────────────────────────┘
 *
 * Cada tópico:
 *   key         sufixo do id (<category>_<key>_NN); único por categoria
 *   category    uma de: cotidiano | humor | trivia | cumprimento | despedida
 *               | pergunta_solta | resposta_curta
 *   seeds       >= 2 rephrasings da MESMA abertura (viram o seed + os typos)
 *   responses   >= 4 respostas plausíveis pra qualquer seed; followups opcional
 *               (lista de textos; alternam B/A/B... a partir de 'A')
 *   emoji_chance / delay [min,max]  parâmetros de humanização
 */

'use strict';

module.exports = [
  // ══════════════════════════════════ COTIDIANO ══════════════════════════════
  {
    key: 'almoco', category: 'cotidiano', emoji_chance: 0.15, delay: [1200, 4500],
    seeds: ['vc ja almoçou?', 'ja almoçou ai?', 'comeu alguma coisa ja?'],
    responses: [
      { text: 'ainda não, to esperando esfriar o arroz kkk', followups: ['kkkk aqui tbm foi corrido', 'bom apetite entao'] },
      { text: 'acabei de comer, hj foi macarrão', followups: ['nossa que fome me deu agora'] },
      { text: 'to indo agora, morrendo de fome' },
      { text: 'hj pulei, so um lanche rapido', followups: ['assim vc passa mal hein'] },
      { text: 'nem me fala, esqueci a marmita em casa' },
    ],
  },
  {
    key: 'cafe', category: 'cotidiano', emoji_chance: 0.2, delay: [1000, 3800],
    seeds: ['to precisando de um café urgente', 'preciso de um café ja', 'so penso em café agora'],
    responses: [
      { text: 'sério kkk aqui já é o terceiro do dia', followups: ['affff nem me fala, hj vai ser longo'] },
      { text: 'boa ideia vou fazer um tbm', followups: ['faz um pra mim ai kkk', 'ta na mao'] },
      { text: 'to sem desde cedo, ta explicado o sono' },
      { text: 'café resolve tudo mesmo kkk' },
    ],
  },
  {
    key: 'cansaco', category: 'cotidiano', emoji_chance: 0.1, delay: [1500, 5000],
    seeds: ['to morto de cansado hj', 'cansado dms hj', 'to acabado hj'],
    responses: [
      { text: 'imagino, a semana ta puxada mesmo', followups: ['to contando os minutos pra deitar'] },
      { text: 'dorme cedo hj entao', followups: ['é o plano kkk', 'faz isso, amanha vc agradece'] },
      { text: 'somos dois, mal consigo manter os olhos abertos' },
      { text: 'toma um banho quente que ajuda' },
    ],
  },
  {
    key: 'fimdesemana', category: 'cotidiano', emoji_chance: 0.15, delay: [1200, 4200],
    seeds: ['planos pro fds?', 'planos pro final de semana?', 'vai fzr oq no fds'],
    responses: [
      { text: 'nada demais, quero só descansar', followups: ['tbm merece né', 'demais kkk'] },
      { text: 'talvez uma praia se o tempo ajudar', followups: ['aeee boa, aproveita'] },
      { text: 'vou visitar a familia, faz tempo que nao vejo' },
      { text: 'ainda nao decidi, deixa rolar' },
    ],
  },
  {
    key: 'feriado', category: 'cotidiano', emoji_chance: 0.12, delay: [1300, 4500],
    seeds: ['vc vai emendar o feriado?', 'vai emendar o feriadão?', 'vc emenda essa?'],
    responses: [
      { text: 'to pensando nisso, seria otimo', followups: ['emenda vale muito a pena kkk'] },
      { text: 'infelizmente não da dessa vez', followups: ['poxa que pena', 'proximo eu emendo'] },
      { text: 'vou sim, ja to contando os dias' },
      { text: 'depende, ainda nao sei' },
    ],
  },
  {
    key: 'aniversario', category: 'cotidiano', emoji_chance: 0.18, delay: [1400, 4800],
    seeds: ['lembra que sabado é aniversario da minha prima', 'sabado é aniversario da prima', 'tem aniversario na familia sabado'],
    responses: [
      { text: 'aaa verdade, vai ter festa?', followups: ['vai ser simples, so a familia', 'esses são os melhores'] },
      { text: 'ja comprou o presente?', followups: ['ainda não kkk vou correr amanha'] },
      { text: 'que legal, manda um parabens meu' },
      { text: 'nossa como o tempo passa né' },
    ],
  },
  {
    key: 'jantar', category: 'cotidiano', emoji_chance: 0.15, delay: [1200, 4500],
    seeds: ['oq vc vai jantar hj?', 'ja pensou no jantar?', 'jantar hj vai ser oq'],
    responses: [
      { text: 'to na duvida entre pizza ou algo rapido', followups: ['pizza sempre ganha kkk'] },
      { text: 'acho que so um sanduiche, to sem animo de cozinhar' },
      { text: 'sobrou almoço, vou esquentar', followups: ['pratico assim'] },
      { text: 'nem sei, vou ver oq tem na geladeira' },
    ],
  },
  {
    key: 'academia', category: 'cotidiano', emoji_chance: 0.15, delay: [1300, 4600],
    seeds: ['vc foi treinar hj?', 'foi pra academia hj?', 'treinou hj?'],
    responses: [
      { text: 'fui sim, hj foi perna, to andando torto kkk', followups: ['kkkk amanha vc sente'] },
      { text: 'faltei, bateu a preguiça', followups: ['relaxa, amanha vc compensa'] },
      { text: 'vou mais tarde ainda, depois que esfriar' },
      { text: 'to tentando voltar na rotina essa semana' },
    ],
  },
  {
    key: 'sono', category: 'cotidiano', emoji_chance: 0.12, delay: [1400, 5000],
    seeds: ['dormi super mal essa noite', 'quase nao preguei o olho ontem', 'acordei varias vezes de noite'],
    responses: [
      { text: 'poxa, deve ta acabado hj', followups: ['to funcionando no cafe kkk'] },
      { text: 'aqui tbm, acho que foi o calor' },
      { text: 'tenta dormir cedo hj pra compensar' },
      { text: 'odeio noite mal dormida, estraga o dia' },
    ],
  },
  {
    key: 'mercado', category: 'cotidiano', emoji_chance: 0.12, delay: [1300, 4600],
    seeds: ['preciso passar no mercado depois', 'ta faltando um monte de coisa em casa', 'tenho que fazer mercado hj'],
    responses: [
      { text: 'aproveita e vai cedo, fica mais vazio', followups: ['boa dica, odeio fila'] },
      { text: 'to precisando tbm, mas to enrolando kkk' },
      { text: 'faz lista senão esquece metade', followups: ['sempre esqueço alguma coisa'] },
      { text: 'os preços tao um absurdo ultimamente' },
    ],
  },
  {
    key: 'faxina', category: 'cotidiano', emoji_chance: 0.12, delay: [1400, 4800],
    seeds: ['hj é dia de faxina aqui em casa', 'vou arrumar a casa hj', 'a casa ta pedindo uma limpeza'],
    responses: [
      { text: 'coragem kkk sempre rende mais do que parece', followups: ['pois é, ja to cansada so de pensar'] },
      { text: 'liga um som que rende mais rapido', followups: ['verdade, faço sempre isso'] },
      { text: 'aqui tbm ta precisando, deixei acumular' },
      { text: 'depois relaxa merecido' },
    ],
  },
  {
    key: 'pet', category: 'cotidiano', emoji_chance: 0.2, delay: [1200, 4400],
    seeds: ['meu cachorro aprontou de novo hj', 'o bicho fez arte hj', 'meu pet ta elétrico hj'],
    responses: [
      { text: 'kkkk oq ele fez dessa vez?', followups: ['cavou o quintal todo', 'kkkkk classico'] },
      { text: 'os bichinhos sao assim mesmo, pura energia' },
      { text: 'manda foto dele depois', followups: ['mando sim kkk'] },
      { text: 'o meu tbm ta agitado, deve ser o tempo' },
    ],
  },

  // ══════════════════════════════════ HUMOR ══════════════════════════════════
  {
    key: 'kkk_sozinho', category: 'humor', emoji_chance: 0.25, delay: [900, 3200],
    seeds: ['kkkkk acabei de lembrar de uma coisa e ri sozinho', 'ri sozinho agora kkkk', 'to rindo sozinho aqui kkk'],
    responses: [
      { text: 'conta ai vai kkk', followups: ['depois te mostro, melhor ver do que explicar', 'ta me deixando curioso'] },
      { text: 'kkkkk rir sozinho é sinal de que ta feliz', followups: ['ou de que to ficando doido kk'] },
      { text: 'kkk contagiou daqui ja to rindo tbm' },
      { text: 'esses momentos aleatorios sao os melhores' },
    ],
  },
  {
    key: 'piada_trocadilho', category: 'humor', emoji_chance: 0.3, delay: [1000, 3500],
    seeds: ['sabia que o café reclamou? disse q tava sendo passado pra tras kkk', 'tenho uma piada de café mas ela é meio coada kkk'],
    responses: [
      { text: 'kkkkkkk que trocadilho horrivel', followups: ['eu sei, mas vc riu', 'ri de nervoso kkk'] },
      { text: 'para com isso kkkk tao ruim que é bom', followups: ['tenho mais uns 10 desse nivel'] },
      { text: 'kkkk saiu quente essa hein' },
      { text: 'me arrependi de perguntar kkk' },
    ],
  },
  {
    key: 'meme_segunda', category: 'humor', emoji_chance: 0.22, delay: [1000, 3600],
    seeds: ['to naquele meme de "segunda-feira me odeia"', 'segunda me odeia kkk', 'to no modo segunda-feira sofrida'],
    responses: [
      { text: 'kkkk somos dois, acordei querendo dormir de novo', followups: ['a cama tava me abraçando, juro'] },
      { text: 'segunda é so um mito, sofrimento coletivo kkk', followups: ['coletivo e obrigatorio', 'kkkkk exato'] },
      { text: 'força ai que sexta chega kkk' },
      { text: 'café duplo hj entao' },
    ],
  },
  {
    key: 'fim_do_mes', category: 'humor', emoji_chance: 0.25, delay: [1000, 3600],
    seeds: ['fim do mes chegando e a conta vazia kkk', 'to naquele modo fim de mes kkk', 'meu cartao ja pediu arrego kkk'],
    responses: [
      { text: 'kkkk somos dois, so miojo ate dia 5', followups: ['classico do brasileiro kkk'] },
      { text: 'ai é hora de inventar receita com o que tem em casa' },
      { text: 'kkkk to fingindo que nao vejo o extrato' },
      { text: 'segura ai que ja ja melhora' },
    ],
  },
  {
    key: 'atraso_zoeira', category: 'humor', emoji_chance: 0.25, delay: [1000, 3400],
    seeds: ['ja to atrasado e ainda nem sai de casa kkk', 'to atrasado como sempre kkk', 'o relogio me odeia hj kkk'],
    responses: [
      { text: 'kkkk classico, corre ai', followups: ['to voando literalmente'] },
      { text: 'manda aquele "ja to chegando" kkk', followups: ['ja mandei faz 20 min kkkk'] },
      { text: 'atraso brasileiro é lei kkk' },
      { text: 'respira e vai com calma senao esquece algo' },
    ],
  },
  {
    key: 'indireta_boa', category: 'humor', emoji_chance: 0.25, delay: [1000, 3600],
    seeds: ['mandaram um audio de 8 min pra mim, socorro kkk', 'recebi audio de 10 min, quem faz isso kkk'],
    responses: [
      { text: 'kkkk coloca na velocidade 2x e reza', followups: ['unica salvação kkk'] },
      { text: 'audio longo é crime, escreve gente kkk' },
      { text: 'kkkk boa sorte ai, depois resume pra mim' },
      { text: 'to rindo pq acabei de fazer isso com alguem' },
    ],
  },

  // ══════════════════════════════════ TRIVIA ═════════════════════════════════
  {
    key: 'clima', category: 'trivia', emoji_chance: 0.12, delay: [1300, 4500],
    seeds: ['q tempo doido né, agora deu uma esfriada', 'que friozinho bom né', 'o tempo mudou total agora'],
    responses: [
      { text: 'pois é, saí e voltei pra pegar casaco', followups: ['fez super bem, ta ventando', 'to nem tirando o moletom hj'] },
      { text: 'aqui ta abafado ainda, deve chover', followups: ['leva guarda chuva por garantia'] },
      { text: 'adoro esse clima, da vontade de ficar em casa' },
      { text: 'nao sei nem como me vestir com esse tempo kkk' },
    ],
  },
  {
    key: 'transito', category: 'trivia', emoji_chance: 0.1, delay: [1400, 4800],
    seeds: ['o transito hj ta impossivel', 'o transito hj ta osso', 'transito impossivel hj'],
    responses: [
      { text: 'ta assim tudo quanto é lugar, parado total', followups: ['to ha 20 min no mesmo farol kkk', 'paciencia, respira'] },
      { text: 'por isso que vim de metrô hj', followups: ['fez certo, amanha faço igual'] },
      { text: 'deve ter dado algum acidente' },
      { text: 'sai de casa mais cedo da proxima' },
    ],
  },
  {
    key: 'futebol', category: 'trivia', emoji_chance: 0.15, delay: [1200, 4200],
    seeds: ['viu o jogo ontem?', 'assistiu o jogo?', 'viu a partida de ontem?'],
    responses: [
      { text: 'vi sim, que fim de jogo doido kkk', followups: ['no fim deu tudo certo', 'sofri mas valeu a pena'] },
      { text: 'não consegui ver, dormi antes', followups: ['perdeu um jogão, depois te conto'] },
      { text: 'vi so o segundo tempo, cheguei tarde' },
      { text: 'nem me lembra, prefiro esquecer kkk' },
    ],
  },
  {
    key: 'novela', category: 'trivia', emoji_chance: 0.15, delay: [1300, 4600],
    seeds: ['perdi o capitulo de ontem, conta oq rolou', 'conta oq rolou na novela', 'perdi o episodio de ontem'],
    responses: [
      { text: 'aaa nem te conto, terminou no melhor kkk', followups: ['serio? agr fiquei curioso', 'assiste depois vale a pena'] },
      { text: 'tbm perdi, tava cansada e apaguei', followups: ['kkk somos dois entao'] },
      { text: 'nao rolou muita coisa, foi enrolação' },
      { text: 'depois te mando o resumo' },
    ],
  },
  {
    key: 'serie_streaming', category: 'trivia', emoji_chance: 0.15, delay: [1300, 4600],
    seeds: ['comecei uma serie nova ontem', 'to viciado numa serie nova', 'achei uma serie boa pra maratonar'],
    responses: [
      { text: 'qual? to precisando de indicação', followups: ['depois te mando o nome', 'boa, vou procurar'] },
      { text: 'cuidado que maratona tira o sono kkk' },
      { text: 'to sem ver nada faz tempo, me perdi nos lançamentos' },
      { text: 'serie boa é perigoso, vc nao dorme mais' },
    ],
  },
  {
    key: 'show_musica', category: 'trivia', emoji_chance: 0.18, delay: [1300, 4600],
    seeds: ['descobri que vai ter show aqui perto', 'soube que vem um show bom pra ca', 'ta rolando pre venda de um show'],
    responses: [
      { text: 'serio? de quem?', followups: ['depois te passo o link', 'aeee bora'] },
      { text: 'faz tempo que nao vou num show', followups: ['tbm, ta na hora né'] },
      { text: 'ingresso deve ta caro, mas vale' },
      { text: 'me avisa se for que eu topo' },
    ],
  },
  {
    key: 'calor_frio', category: 'trivia', emoji_chance: 0.12, delay: [1300, 4500],
    seeds: ['ta um calor insuportavel hj', 'que calor é esse hj', 'to derretendo com esse calor'],
    responses: [
      { text: 'aqui tbm, o ventilador nao da conta', followups: ['ja pensei em dormir no chao gelado kkk'] },
      { text: 'bebe bastante agua com esse calor' },
      { text: 'e eu aqui de moletom achando pouco kkk' },
      { text: 'so queria um ar condicionado agora' },
    ],
  },

  // ═══════════════════════════════ CUMPRIMENTO ═══════════════════════════════
  {
    key: 'bomdia', category: 'cumprimento', emoji_chance: 0.2, delay: [800, 3000],
    seeds: ['bom diaaa, tudo certo?', 'bom dia, tudo certo?', 'eae bom dia'],
    responses: [
      { text: 'bom dia! tudo sim e vc?', followups: ['tudo tranquilo, so acordando ainda kkk', 'kkk bom começo de dia'] },
      { text: 'opa bom dia, começando agora por aqui', followups: ['bora que hj rende'] },
      { text: 'bom dia! dormiu bem?' },
      { text: 'bom diaa, ja tomando o cafezinho aqui' },
    ],
  },
  {
    key: 'boatarde', category: 'cumprimento', emoji_chance: 0.18, delay: [900, 3200],
    seeds: ['boa tarde, como ta ai?', 'boa tarde, tudo bem?', 'boa tardee'],
    responses: [
      { text: 'boa tarde! tudo por aqui, e ai?', followups: ['tarde produtiva ate agora'] },
      { text: 'opa boa tarde, so na correria kkk' },
      { text: 'boa tarde! dia rendendo?' },
      { text: 'boa tarde, ja querendo que chegue a noite kkk' },
    ],
  },
  {
    key: 'boanoite', category: 'cumprimento', emoji_chance: 0.18, delay: [900, 3200],
    seeds: ['boa noite, ja indo dormir?', 'boa noite, ja vai dormir?', 'boa noiteee'],
    responses: [
      { text: 'quase, so terminando de ver uma coisa', followups: ['nao dorme tarde hein kkk'] },
      { text: 'boa noite! hj vou dormir cedo', followups: ['faz bem, descansa', 'vc tbm, ate amanha'] },
      { text: 'ainda nao, sem sono por enquanto' },
      { text: 'boa noite! foi um dia longo hein' },
    ],
  },
  {
    key: 'oi_sumido', category: 'cumprimento', emoji_chance: 0.18, delay: [900, 3400],
    seeds: ['oi sumido, quanto tempo', 'oii, ta vivo? kkk', 'apareceu hein, sumido'],
    responses: [
      { text: 'kkk verdade, a correria engole a gente', followups: ['precisamos marcar algo', 'bora sim'] },
      { text: 'to por aqui, so meio enrolado', followups: ['imagino, depois a gente conversa'] },
      { text: 'oiii que saudade, tudo bem com vc?' },
      { text: 'kkk desculpa o sumiço, foi mal' },
    ],
  },
  {
    key: 'eae', category: 'cumprimento', emoji_chance: 0.2, delay: [800, 3000],
    seeds: ['eae, blz?', 'eai tudo certo?', 'fala, como vc ta?'],
    responses: [
      { text: 'suave e vc?', followups: ['tudo certo por aqui'] },
      { text: 'blz demais, so na correria', followups: ['te entendo kkk'] },
      { text: 'tudo tranquilo, e ai?' },
      { text: 'de boa, aproveitando o dia' },
    ],
  },

  // ═══════════════════════════════ DESPEDIDA ═════════════════════════════════
  {
    key: 'vou_nessa', category: 'despedida', emoji_chance: 0.2, delay: [800, 2800],
    seeds: ['vou nessa, depois a gente fala', 'vou nessa, depois falo', 'to indo, a gente se fala'],
    responses: [
      { text: 'blz, qualquer coisa chama', followups: ['fechou, flw'] },
      { text: 'ta bom, se cuida ai', followups: ['vc tbm, abraço', 'valeu'] },
      { text: 'flw, ate mais' },
      { text: 'vai com deus, depois conversamos' },
    ],
  },
  {
    key: 'ja_vou', category: 'despedida', emoji_chance: 0.2, delay: [800, 2800],
    seeds: ['ja vou indo que ta tarde', 'preciso ir, ta na hora', 'vou ter que sair agora'],
    responses: [
      { text: 'tranquilo, ate depois', followups: ['valeu, flw'] },
      { text: 'blz, boa ai pra vc' },
      { text: 'ta certo, se cuida', followups: ['vc tbm'] },
      { text: 'ok, qualquer coisa me chama' },
    ],
  },
  {
    key: 'indo_dormir', category: 'despedida', emoji_chance: 0.18, delay: [900, 3000],
    seeds: ['vou dormir que amanha acordo cedo', 'to indo deitar, boa noite', 'ja vou dormir, to caindo'],
    responses: [
      { text: 'boa noite, descansa', followups: ['vc tbm, ate amanha'] },
      { text: 'faz bem, dorme bem ai' },
      { text: 'boa noite! sonha com coisa boa' },
      { text: 'ok, ate amanha entao' },
    ],
  },
  {
    key: 'ate_amanha', category: 'despedida', emoji_chance: 0.18, delay: [800, 2800],
    seeds: ['ate amanha entao', 'a gente se fala amanha', 'te vejo amanha'],
    responses: [
      { text: 'ate amanha, bom descanso', followups: ['valeu, vc tbm'] },
      { text: 'fechou, amanha continuamos' },
      { text: 'ta bom, ate la' },
      { text: 'combinado, boa noite ai' },
    ],
  },

  // ════════════════════════════ PERGUNTA SOLTA ═══════════════════════════════
  {
    key: 'viu_aquilo', category: 'pergunta_solta', emoji_chance: 0.15, delay: [1000, 3600],
    seeds: ['vc viu aquilo que te mandei?', 'vc viu aquilo?', 'viu o que te mandei?'],
    responses: [
      { text: 'vi sim, mto bom kkk', followups: ['né, achei que ia gostar', 'gostei demais, valeu'] },
      { text: 'ainda nao consegui ver, vou olhar já', followups: ['olha sim, depois me fala'] },
      { text: 'vi por cima, depois vejo com calma' },
      { text: 'ainda nao abri, to sem sinal bom aqui' },
    ],
  },
  {
    key: 'cade_vc', category: 'pergunta_solta', emoji_chance: 0.15, delay: [1000, 3800],
    seeds: ['cadê vc? sumiu', 'cade vc sumido', 'sumiu hein, ta onde?'],
    responses: [
      { text: 'to por aqui, so meio corrido hj', followups: ['imagino, depois a gente conversa'] },
      { text: 'aqui ó kkk nao sumi nao', followups: ['ta certo entao kkk'] },
      { text: 'to resolvendo umas coisas, ja te chamo' },
      { text: 'saudades tbm, bora marcar algo' },
    ],
  },
  {
    key: 'to_onibus', category: 'pergunta_solta', emoji_chance: 0.12, delay: [1200, 4200],
    seeds: ['to no onibus ainda, deve demorar', 'to no busao, vai demorar', 'to a caminho mas ta longe'],
    responses: [
      { text: 'tranquilo, sem pressa', followups: ['valeu por entender kkk'] },
      { text: 'ta muito cheio ai?', followups: ['lotado, nem consigo sentar', 'eca, força ai'] },
      { text: 'avisa quando tiver chegando' },
      { text: 'ok, vou te esperando entao' },
    ],
  },
  {
    key: 'chegou_bem', category: 'pergunta_solta', emoji_chance: 0.15, delay: [1000, 3600],
    seeds: ['chegou bem em casa?', 'chegou ai direitinho?', 'ja chegou?'],
    responses: [
      { text: 'cheguei sim, obrigado por perguntar', followups: ['que bom, fico tranquilo'] },
      { text: 'cheguei agora, foi tranquilo' },
      { text: 'ainda no caminho, quase la' },
      { text: 'cheguei, ja to de pijama kkk' },
    ],
  },
  {
    key: 'ainda_ai', category: 'pergunta_solta', emoji_chance: 0.15, delay: [1000, 3600],
    seeds: ['vc ainda ta ai?', 'ta ai ainda?', 'continua ai?'],
    responses: [
      { text: 'to sim, pode falar', followups: ['blz, so confirmando'] },
      { text: 'to aqui, so distraido no cel kkk' },
      { text: 'to indo embora daqui a pouco' },
      { text: 'to, oq vc precisa?' },
    ],
  },

  // ════════════════════════════ RESPOSTA CURTA ═══════════════════════════════
  {
    key: 'ok_combinado', category: 'resposta_curta', emoji_chance: 0.35, delay: [600, 2200],
    seeds: ['combinado entao, te aviso qnd chegar', 'fechou, te aviso qnd chegar', 'entao ta, te falo depois'],
    responses: [
      { text: 'ok', followups: ['👍'] },
      { text: 'blz, fico no aguardo', followups: ['fechou'] },
      { text: 'perfeito' },
      { text: 'combinado' },
    ],
  },
  {
    key: 'valeu_resolvido', category: 'resposta_curta', emoji_chance: 0.3, delay: [700, 2600],
    seeds: ['consegui resolver aquilo, era simples no fim', 'resolvi aquilo, era facil', 'deu tudo certo com aquilo'],
    responses: [
      { text: 'vlw por avisar, fico tranquilo', followups: ['tmj'] },
      { text: 'boa! sabia que ia dar certo', followups: ['kkk valeu a força'] },
      { text: 'aeee que otimo' },
      { text: 'top, fico feliz' },
    ],
  },
  {
    key: 'blz_aguardo', category: 'resposta_curta', emoji_chance: 0.3, delay: [700, 2400],
    seeds: ['pode deixar que eu vejo isso mais tarde', 'depois eu olho e te falo', 'deixa comigo que eu resolvo'],
    responses: [
      { text: 'blz', followups: ['valeu'] },
      { text: 'tranquilo, sem pressa' },
      { text: 'fechou entao' },
      { text: 'ok, obrigado' },
    ],
  },
  {
    key: 'tmj_forca', category: 'resposta_curta', emoji_chance: 0.35, delay: [700, 2400],
    seeds: ['obrigado pela força ai', 'valeu mesmo pela ajuda', 'vc me salvou hj, obrigado'],
    responses: [
      { text: 'tmj', followups: ['sempre 🤝'] },
      { text: 'imagina, disponha' },
      { text: 'que isso, precisando é so chamar' },
      { text: 'nao foi nada' },
    ],
  },
  {
    key: 'positivo', category: 'resposta_curta', emoji_chance: 0.3, delay: [600, 2200],
    seeds: ['deu certo aqui, tudo funcionando', 'consegui, ta tudo ok agora', 'ficou pronto, ta certinho'],
    responses: [
      { text: 'show', followups: ['👏'] },
      { text: 'boa demais' },
      { text: 'perfeito entao' },
      { text: 'top, era isso mesmo' },
    ],
  },
];
