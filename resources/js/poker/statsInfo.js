// The tell codex: every HUD stat explained — a short whisper for hover
// popups, a longer read for the /stats-guide page. Keyed by computed stat key.
export const STAT_INFO = {
  name: {
    title: 'Player Name',
    short: 'Who you are reading.',
    long: 'The player this line of numbers describes. Click any name on the site to open their full Sharkscope-style dossier.',
  },
  hands: {
    title: 'Hands',
    short: 'Sample size — trust nothing under a few hundred.',
    long: 'How many archived hands feed these numbers. Small samples lie: VPIP stabilizes after ~100 hands, positional and c-bet stats need 500+, and bb/100 can mislead for tens of thousands. The bigger this number, the sharper the read.',
  },
  vpip: {
    title: 'VPIP — Voluntarily Put $ In Pot',
    short: '% of hands they choose to play. High = loose, low = tight.',
    long: 'The percentage of dealt hands where the player voluntarily committed chips before the flop (calls, bets, raises — blinds don\'t count). The single fastest read on a player: under ~18% is tight, 20–28% is standard, beyond 35% is loose, beyond 50% is a party. Read it together with PFR.',
  },
  pfr: {
    title: 'PFR — Preflop Raise',
    short: '% of hands they raise preflop. The gap to VPIP shows passivity.',
    long: 'How often the player raises before the flop. A PFR close to VPIP (e.g. 22/18) marks an aggressive player who enters pots raising; a wide gap (e.g. 30/5) marks a passive caller — the classic calling station. Aggression wins pots that cards don\'t.',
  },
  af: {
    title: 'AF — Total Aggression Factor',
    short: '(bets + raises) ÷ calls after the flop. Above ~2 is aggressive.',
    long: 'Postflop aggression: how many bets-and-raises the player makes for every call. AF under 1 is passive (calls down, rarely pressures), 1.5–2.5 is balanced, 3+ is hyper-aggressive — give them rope to bluff. Computed across flop, turn and river.',
  },
  threebet: {
    title: '3Bet Preflop',
    short: '% of chances they re-raise a raiser. 8%+ is fighting back wide.',
    long: 'When someone has already raised, how often does this player re-raise (3-bet)? Under 4% means a 3-bet is almost always a premium — fold light. 6–9% is a healthy modern range. Above 12% they are leveraging fold equity with non-premiums; 4-bet or call wider.',
  },
  fold3bet: {
    title: 'F3B — Fold to 3Bet After Raising',
    short: 'How often their open folds to a re-raise. High = 3-bet them.',
    long: 'After this player raises and someone 3-bets, how often do they give up preflop? Above ~65% they open wide and surrender — print money by 3-betting light. Under ~45% they defend their opens; 3-bet them for value, not as a bluff.',
  },
  fourbet: {
    title: '4Bet+ Ratio',
    short: 'How often they re-raise over a 3-bet. Usually monsters.',
    long: 'Facing a 3-bet, how often does this player come back over the top? Low single digits is standard and means QQ+/AK territory. A high number marks a leveling war player whose 4-bets you can fight back against.',
  },
  fold4bet: {
    title: 'F4B — Fold to 4Bet After 3Betting',
    short: 'Their 3-bets that surrender to a 4-bet.',
    long: 'After 3-betting, how often do they fold when the original raiser 4-bets? High numbers reveal light 3-bettors you can 4-bet-bluff; low numbers mean their 3-bet stack-off range is real.',
  },
  cbet_f: {
    title: 'CBF — Continuation Bet Flop',
    short: 'How often the preflop raiser fires the flop.',
    long: 'When this player raised preflop and got called, how often do they bet the flop regardless of what it brought? 50–70% is standard. Above 80% they fire at everything — raise or float them on dry boards. Below 40%, their flop bets are honest.',
  },
  cbet_t: {
    title: 'CBT — Continuation Bet Turn',
    short: 'Second barrel frequency. Honesty lives on the turn.',
    long: 'Having c-bet the flop, how often do they keep firing on the turn? Most players slow down dramatically — a low CBT after a high CBF means one peel floats them off most hands. A high double-barrel rate needs real defending.',
  },
  cbet_r: {
    title: 'CBR — Continuation Bet River',
    short: 'Third barrel frequency. The rarest courage.',
    long: 'How often the aggressor empties the clip on the river. Triple-barrels above ~50% include bluffs you must catch; under ~30% the river bet is value — your bluff-catchers shrivel.',
  },
  fold_cbet_f: {
    title: 'FFCB — Fold to Flop CBet',
    short: 'How often they surrender to a flop c-bet.',
    long: 'Facing a continuation bet on the flop, how often do they fold? Above ~60% your c-bets profit with any two cards. Under ~40% they peel wide — c-bet for value and barrel scare cards.',
  },
  fold_cbet_t: {
    title: 'FTCB — Fold to Turn CBet',
    short: 'Folds to the second barrel.',
    long: 'How often they fold when the aggressor fires again on the turn. Players who call flop wide and fold turn often (high FTCB) are double-barrel targets.',
  },
  fold_cbet_r: {
    title: 'FRCB — Fold to River CBet',
    short: 'Folds to the third barrel.',
    long: 'How often the river bet takes it. A high number invites triple-barrel bluffs; a low one means they call you down — value bet thinner, bluff never.',
  },
  wtsd: {
    title: 'WTSD — Went to Showdown',
    short: 'Of hands that saw a flop, how many reach showdown. High = sticky.',
    long: 'How often the player takes a flopped hand all the way to showdown. Above ~32% is a calling station — stop bluffing, start value betting thin. Under ~22% they fold along the way — barrel them relentlessly.',
  },
  wsd: {
    title: 'W$SD — Won at Showdown',
    short: 'How often their showdowns win. >55% = solid hands only.',
    long: 'When they do show a hand down, how often it is the best. High W$SD with low WTSD means they only arrive with the goods. Low W$SD with high WTSD is the profile of a station paying off value bets.',
  },
  wwsf: {
    title: 'WWSF — Won When Saw Flop',
    short: 'How often seeing a flop ends with them taking the pot.',
    long: 'Of all hands where the player saw a flop, the share they eventually won (by showdown or by everyone folding). Healthy aggression keeps this near 45–50%; passive players drift to 40% or below because they never take pots away.',
  },
  bb100: {
    title: 'BB/100 — Win Rate',
    short: 'Big blinds won per 100 hands. The bottom line.',
    long: 'The universal poker win-rate: big blinds won per hundred hands, across all stakes normalized. Positive is winning; +5 is strong; +10 is crushing. Needs tens of thousands of hands before variance stops lying.',
  },
};
