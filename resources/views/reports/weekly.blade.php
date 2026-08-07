@php
    /** @var array $data */
    $d = $data;
    $a = $d['activity']; $res = $d['result']; $lost = $d['lost'];
    $att = $d['attention']; $warm = $d['warm']; $rfq = $d['stuck_rfq'];
    $fmt = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $rurl = fn ($id) => route('requests.show', $id);
    $toneClass = ['red' => 'red', 'amber' => 'amber', 'neutral' => ''];
    $attnEmpty = empty($att['overdue']) && empty($att['waiting']);
    $warmEmpty = empty($warm['price_ready']) && empty($warm['awaiting_invoice']);
@endphp
<div class="wk">
<style>
  .wk{--n0:#fff;--n50:oklch(98.6% 0.003 250);--n100:oklch(96.8% 0.005 250);--n200:oklch(93.5% 0.007 250);--n300:oklch(88% 0.010 250);--n400:oklch(74% 0.012 250);--n500:oklch(58% 0.015 250);--n700:oklch(34% 0.018 250);--n900:oklch(16% 0.020 250);
    --red50:oklch(97% 0.015 25);--red600:#D32027;--red700:oklch(48% 0.205 25);
    --amb50:oklch(97.5% 0.030 85);--amb600:oklch(64% 0.165 75);--amb700:oklch(54% 0.155 65);
    --em50:oklch(97% 0.030 160);--em700:oklch(48% 0.130 160);
    --sky50:oklch(97% 0.025 230);--sky500:oklch(64% 0.150 235);--sky700:oklch(46% 0.155 235);
    --brd:var(--n200);--sans:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,system-ui,sans-serif;--mono:'JetBrains Mono',ui-monospace,'SF Mono',Menlo,Consolas,monospace;
    max-width:660px;margin:0 auto;background:var(--n0);border:1px solid var(--brd);border-radius:6px;overflow:hidden;font-family:var(--sans);color:var(--n900);font-size:13px;line-height:1.5}
  .wk *{box-sizing:border-box}
  .wk .pageh{padding:16px 22px 14px;border-bottom:1px solid var(--brd)}
  .wk .crumbs{font:500 11px/1 var(--sans);color:var(--n500);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
  .wk h1{font:600 20px/1.2 var(--sans);margin:0;letter-spacing:-0.005em}
  .wk .meta{font:400 12.5px/1 var(--sans);color:var(--n500);margin-top:5px}
  .wk .sec{padding:16px 22px;border-bottom:1px solid var(--brd)}
  .wk .sec:last-child{border-bottom:none}
  .wk .sech{font:600 13px/1.3 var(--sans);margin:0 0 12px;display:flex;align-items:center;gap:9px}
  .wk .badge{font:600 10.5px/1.3 var(--sans);padding:2px 7px;border-radius:999px;background:var(--n100);color:var(--n700)}
  .wk .badge.red{background:var(--red50);color:var(--red700)} .wk .badge.amber{background:var(--amb50);color:var(--amb700)}
  .wk .hint{font:400 12px/1.4 var(--sans);color:var(--n500);margin:-6px 0 12px}
  .wk .kpi{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
  .wk .kpi .c{border:1px solid var(--brd);border-radius:6px;padding:12px 14px}
  .wk .kpi .k{font:600 10.5px/1.2 var(--sans);color:var(--n500);text-transform:uppercase;letter-spacing:.04em}
  .wk .kpi .v{font:600 24px/1.1 var(--sans);margin-top:8px;font-variant-numeric:tabular-nums;letter-spacing:-0.02em}
  .wk .kpi .v small{font:500 12px/1 var(--sans);color:var(--n500);margin-left:3px}
  .wk .kpi .d{font:500 11px/1.3 var(--mono);color:var(--n500);margin-top:6px;font-variant-numeric:tabular-nums}
  .wk .kpi .c.ok .v{color:var(--em700)}
  .wk .result{display:flex;align-items:center;gap:22px;flex-wrap:wrap;background:var(--em50);border:1px solid var(--brd);border-radius:6px;padding:16px 20px}
  .wk .result .big{font:700 34px/1 var(--sans);color:var(--em700);font-variant-numeric:tabular-nums;letter-spacing:-0.02em}
  .wk .result .u{font:400 12.5px/1.3 var(--sans);color:var(--n700)}
  .wk .result .amt{font:600 20px/1 var(--sans);font-variant-numeric:tabular-nums}
  .wk .result .cap{font:500 11px/1.3 var(--sans);color:var(--n500);margin-top:4px}
  .wk .result .paid{margin-left:auto;text-align:right}
  .wk .result .paid .v{font:600 16px/1 var(--sans);font-variant-numeric:tabular-nums}
  .wk .grp{border:1px solid var(--brd);border-radius:6px;overflow:hidden;margin-bottom:10px}
  .wk .grp .gh{display:flex;align-items:center;gap:8px;padding:9px 13px;font:600 12px/1.3 var(--sans);border-bottom:1px solid var(--brd)}
  .wk .grp.red .gh{background:var(--red50);color:var(--red700)} .wk .grp.amber .gh{background:var(--amb50);color:var(--amb700)} .wk .grp.sky .gh{background:var(--sky50);color:var(--sky700)}
  .wk .dot{width:7px;height:7px;border-radius:999px;flex:none}
  .wk .dot.red{background:var(--red600)} .wk .dot.amber{background:var(--amb600)} .wk .dot.sky{background:var(--sky500)}
  .wk .gh .cnt{margin-left:auto;font-family:var(--mono);font-weight:700;font-variant-numeric:tabular-nums}
  .wk .r{display:flex;align-items:center;gap:10px;padding:8px 13px;font-size:12.5px;border-bottom:1px solid var(--n100)}
  .wk .r:last-child{border-bottom:none}
  .wk .r .code{font:600 12px/1 var(--mono);color:var(--red600);text-decoration:none}
  .wk .r a.code:hover{text-decoration:underline}
  .wk .r .st{font:400 11.5px/1 var(--sans);color:var(--n500)}
  .wk .r .m{margin-left:auto;font:500 11.5px/1 var(--mono);color:var(--n700);font-variant-numeric:tabular-nums}
  .wk .r .m.hot{color:var(--red700)}
  .wk .chips{display:flex;flex-wrap:wrap;gap:6px}
  .wk .chip{font:500 11.5px/1 var(--sans);padding:5px 10px;border-radius:999px;border:1px solid var(--brd)}
  .wk .chip.amber{background:var(--amb50);color:var(--amb700);border-color:transparent}
  .wk .chip.mute{background:var(--n100);color:var(--n500);border-color:transparent}
  .wk .btn{display:inline-block;margin-top:13px;background:var(--red600);color:#fff;text-decoration:none;font:500 12.5px/1 var(--sans);padding:9px 14px;border-radius:6px}
  .wk .foot{padding:12px 22px;font:400 11px/1.4 var(--mono);color:var(--n400);background:var(--n100)}
  .wk .empty{font:400 12.5px/1.4 var(--sans);color:var(--n500)}
  .wk b.hl{color:var(--n900)}
</style>

  <div class="pageh">
    <div class="crumbs">Еженедельный отчёт менеджера</div>
    <h1>{{ $d['manager']['name'] }}</h1>
    <div class="meta">{{ $d['period']['label'] }} · неделя {{ $d['period']['week'] }}</div>
  </div>

  {{-- Итоги --}}
  <div class="sec">
    <div class="sech">Итоги недели</div>
    <div class="kpi">
      <div class="c"><div class="k">Назначено заявок</div><div class="v">{{ $a['assigned'] }}</div></div>
      <div class="c"><div class="k">Писем отправлено</div><div class="v">{{ $a['emails_total'] }}</div><div class="d">{{ $a['emails_system'] }} через систему</div></div>
      <div class="c"><div class="k">Запросов поставщикам</div><div class="v">{{ $a['rfqs'] }}</div><div class="d">{{ $a['offers'] }} предложений получено</div></div>
      <div class="c"><div class="k">КП выдано</div><div class="v">{{ $a['kp_count'] }}</div><div class="d">на {{ $fmt($a['kp_sum']) }} ₽ · {{ $a['kp_system'] }} через систему</div></div>
      <div class="c"><div class="k">Счетов выставлено</div><div class="v">{{ $a['inv_count'] }}</div><div class="d">на {{ $fmt($a['inv_sum']) }} ₽</div></div>
      <div class="c ok"><div class="k">Оплачено</div><div class="v">{{ $fmt($a['paid_sum']) }}<small>₽</small></div></div>
    </div>
  </div>

  {{-- Результат --}}
  <div class="sec">
    <div class="sech">Результат</div>
    <div class="result">
      <div style="display:flex;align-items:baseline;gap:8px"><span class="big">{{ $res['won'] }}</span><span class="u">успешно<br>закрытых заказов</span></div>
      <div><div class="amt">{{ $fmt($res['won_sum']) }} ₽</div><div class="cap">на сумму</div></div>
      <div class="paid"><div class="v">{{ $fmt($res['paid_sum']) }} ₽</div><div class="cap">поступило оплат</div></div>
    </div>
  </div>

  {{-- Потеряно --}}
  @if($lost['total'] > 0)
  <div class="sec">
    <div class="sech">Потеряно <span class="badge">{{ $lost['total'] }}</span></div>
    <p class="hint">{{ $lost['real'] }} реальных потерь@if($lost['noise'] > 0) и {{ $lost['noise'] }} закрытий «не наша тема / спам / дубль»@endif. Ниже — на каком этапе ушли реальные.</p>
    <div class="grp">
      <div class="rows">
        @foreach($lost['stages'] as $s)
          <div class="r">
            <span class="dot {{ $toneClass[$s['tone']] ?: '' }}" @if(!$toneClass[$s['tone']]) style="visibility:hidden" @endif></span>
            <span>{{ $s['label'] }}</span>
            <span class="m {{ $s['tone']==='red' ? 'hot' : '' }}">@if($s['sum'] !== null)<b class="hl">{{ $fmt($s['sum']) }} ₽</b> · @endif{{ $s['count'] }}</span>
          </div>
        @endforeach
      </div>
    </div>
  </div>
  @endif

  {{-- Требует внимания --}}
  @if(!$attnEmpty)
  <div class="sec">
    <div class="sech">Требует внимания <span class="badge red">{{ count($att['overdue']) + count($att['waiting']) }}</span></div>
    <p class="hint">Мяч на нашей стороне. Разобрать в первую очередь.</p>
    @if(!empty($att['overdue']))
      <div class="grp red">
        <div class="gh"><span class="dot red"></span>Просрочено — наш долг, без движения свыше 3 дней <span class="cnt">{{ count($att['overdue']) }}</span></div>
        <div class="rows">@foreach($att['overdue'] as $x)
          <div class="r"><a class="code" href="{{ $rurl($x['id']) }}">{{ $x['code'] }}</a><span class="st">{{ $x['status'] }}</span><span class="m">{{ $x['meta'] }}</span></div>
        @endforeach</div>
      </div>
    @endif
    @if(!empty($att['waiting']))
      <div class="grp amber">
        <div class="gh"><span class="dot amber"></span>Клиент написал последним — ждёт ответа <span class="cnt">{{ count($att['waiting']) }}</span></div>
        <div class="rows">@foreach($att['waiting'] as $x)
          <div class="r"><a class="code" href="{{ $rurl($x['id']) }}">{{ $x['code'] }}</a><span class="st">{{ $x['status'] }}</span><span class="m {{ ($x['days'] ?? 0) >= 7 ? 'hot' : '' }}">{{ $x['meta'] }}</span></div>
        @endforeach</div>
      </div>
    @endif
  </div>
  @endif

  {{-- Застряли у нас --}}
  @if(!$warmEmpty)
  <div class="sec">
    <div class="sech">Застряли у нас — дожать <span class="badge">{{ count($warm['price_ready']) + count($warm['awaiting_invoice']) }}</span></div>
    <p class="hint">Тёплые заявки, где дело за нами: отправить КП или счёт.</p>
    @if(!empty($warm['awaiting_invoice']))
      <div class="grp sky">
        <div class="gh"><span class="dot sky"></span>Клиент ждёт счёт после КП <span class="cnt">{{ count($warm['awaiting_invoice']) }}</span></div>
        <div class="rows">@foreach($warm['awaiting_invoice'] as $x)
          <div class="r"><a class="code" href="{{ $rurl($x['id']) }}">{{ $x['code'] }}</a><span class="st">{{ $x['status'] }}</span><span class="m">{{ $x['meta'] }}</span></div>
        @endforeach</div>
      </div>
    @endif
    @if(!empty($warm['price_ready']))
      <div class="grp sky">
        <div class="gh"><span class="dot sky"></span>Появилась актуальная цена, но КП не отправлен <span class="cnt">{{ count($warm['price_ready']) }}</span></div>
        <div class="rows">@foreach($warm['price_ready'] as $x)
          <div class="r"><a class="code" href="{{ $rurl($x['id']) }}">{{ $x['code'] }}</a><span class="st">{{ $x['status'] }}</span><span class="m">{{ $x['meta'] }}</span></div>
        @endforeach</div>
      </div>
    @endif
  </div>
  @endif

  {{-- Зависшие RFQ --}}
  @if($rfq['count'] > 0)
  <div class="sec">
    <div class="sech">Запросы поставщикам без ответа <span class="badge amber">{{ $rfq['count'] }}</span></div>
    <p class="hint">Поставщик молчит после напоминаний или ответил без цены и без отказа. Закрыть отказом, вернуть в напоминания или запросить заново.</p>
    <div class="chips">
      @foreach($rfq['examples'] as $e)
        <span class="chip {{ $e['answered'] ? 'amber' : 'mute' }}">{{ \Illuminate\Support\Str::limit($e['name'], 34) }} · {{ $e['state'] }}</span>
      @endforeach
      @if($rfq['count'] > count($rfq['examples']))<span class="chip mute">ещё {{ $rfq['count'] - count($rfq['examples']) }}</span>@endif
    </div>
    <a class="btn" href="{{ route('procurement.index') }}">Снабжение → мои запросы</a>
  </div>
  @endif

  @if($attnEmpty && $warmEmpty && $rfq['count'] === 0)
  <div class="sec"><div class="empty">Хвостов нет — все заявки в работе, поставщики отвечают. Хорошая неделя.</div></div>
  @endif

  <div class="foot">Сформировано автоматически · MyLift CRM · {{ $d['period']['label'] }}</div>
</div>
