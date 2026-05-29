<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>{{ $company['legal_name'] }} · КП {{ $q->internal_code }}</title>
<style>
* { box-sizing: border-box; }
/* dompdf поддерживает @page margin криво (особенно 3-значный shorthand),
   поля задаём через padding на body — это работает железно. */
@page { margin: 0; size: A4 portrait; }
html { margin: 0; padding: 0; }
body { margin: 0; padding: 9mm 12mm 7mm 12mm; background: #fff; font-family: 'PT Sans', sans-serif; color: #0f1419; font-size: 9pt; line-height: 1.25; }
/* width 186mm = A4 210mm − 2×12mm padding. */
.sheet { width: 186mm; color: #0f1419; }

/* Top notice — узкая преамбула */
.notice { font: 8pt/1.3 'PT Sans', sans-serif; color: #5c6470; padding-bottom: 1.5mm; border-bottom: 0.4pt solid #c0c4ca; margin-bottom: 1.8mm; }
.notice p { margin: 0 0 1mm; }
.notice .warn { color: #0f1419; font-weight: bold; }

/* Header */
.head { display: table; width: 100%; margin-bottom: 1.8mm; }
.head .row { display: table-row; }
.head .logo { display: table-cell; width: 26mm; vertical-align: middle; }
.head .logo img { width: 24mm; height: auto; display: block; }
.head .brand { display: table-cell; padding-left: 6mm; vertical-align: middle; font: bold 16pt/1.1 'PT Sans', sans-serif; letter-spacing: -0.2pt; }
.head .brand small { display: block; font: 8.5pt/1.3 'PT Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.3pt; margin-top: 1mm; font-weight: normal; }
.head .title { display: table-cell; text-align: right; vertical-align: middle; }
.head .title h1 { margin: 0 0 1mm; font: bold 14pt/1.2 'PT Sans', sans-serif; color: #0f1419; }
.head .title .num { font: bold 11pt/1.3 'PT Sans', sans-serif; }
.head .title .num .code { font-family: 'PT Mono', monospace; color: #D32027; }
.head .title .date { font: 9pt/1.3 'PT Sans', sans-serif; color: #5c6470; margin-top: 1mm; }
.head .title .stripe { display: inline-block; height: 1.4pt; background: #D32027; width: 40mm; margin-top: 2mm; }

/* Parties */
.parties { margin-bottom: 1.8mm; font: 9pt/1.32 'PT Sans', sans-serif; }
.parties .p { margin-bottom: 0.6mm; display: table; width: 100%; }
.parties .p .lbl { display: table-cell; width: 30mm; font: bold 8.5pt/1.3 'PT Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; vertical-align: top; padding-top: 0.4mm; }
.parties .p .val { display: table-cell; color: #0f1419; vertical-align: top; }
.parties .val b { font-weight: bold; }
.parties .val .mono { font-family: 'PT Mono', monospace; font-size: 9pt; }

/* Содержание запроса */
.subj { margin-bottom: 1.8mm; padding: 1.8mm 3.5mm; background: #f6f7f9; border-radius: 1.5mm; font: 8.5pt/1.4 'PT Sans', sans-serif; color: #0f1419; }
.subj .lbl { font-weight: bold; font-size: 8pt; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; }
.subj .it b { color: #5c6470; font-weight: bold; }
.subj .sep { color: #c0c4ca; }

/* Items */
.items { width: 100%; border-collapse: collapse; font: 8.5pt/1.3 'PT Sans', sans-serif; margin-bottom: 1.8mm; }
.items thead th { background: #f4f6f9; color: #0f1419; font: bold 8pt/1.2 'PT Sans', sans-serif; padding: 1.5mm 1.6mm; text-align: left; vertical-align: middle; border-bottom: 0.6pt solid #0f1419; border-right: 0.3pt solid #d8dce3; }
.items thead th:last-child { border-right: none; }
.items thead th.r { text-align: right; }
.items tbody td { padding: 1.1mm 1.6mm; border-bottom: 0.4pt solid #e3e6eb; vertical-align: top; font-size: 8.5pt; }
.items tbody tr.even td { background: #fafbfc; }
.items .num { text-align: right; color: #5c6470; font-family: 'PT Mono', monospace; font-size: 8pt; }
.items .name .t { font-weight: bold; color: #0f1419; line-height: 1.3; font-size: 9pt; }
.items .name .art { font-family: 'PT Mono', monospace; color: #5c6470; font-size: 8pt; margin-top: 0.6mm; }
.items .term { font-size: 8.5pt; line-height: 1.3; color: #0f1419; }
.items .term .s { display: block; color: #5c6470; font-weight: normal; font-size: 7.5pt; margin-top: 0.4mm; }
.items .qty { text-align: right; color: #0f1419; font-weight: bold; }
.items .qty small { color: #5c6470; font-weight: normal; font-size: 7.5pt; margin-left: 1mm; }
.items .pricebox { text-align: right; line-height: 1.2; }
.items .pricebox .now { color: #0f1419; font-weight: bold; font-size: 9.5pt; font-family: 'PT Mono', monospace; display: block; margin-bottom: 0.4mm; }
.items .pricebox .wasline { display: block; }
.items .pricebox .was { color: #9aa0a8; font-family: 'PT Mono', monospace; text-decoration: line-through; font-size: 7.5pt; }
.items .pricebox .disc { color: #0f6e3a; font-weight: bold; font-size: 7.5pt; }
.items .sum { text-align: right; font-family: 'PT Mono', monospace; color: #0f1419; font-weight: bold; font-size: 9pt; line-height: 1.25; }
.items .vat { display: block; color: #5c6470; font-weight: normal; font-size: 7pt; margin-top: 0.3mm; font-family: 'PT Sans', sans-serif; }
/* PT Mono не содержит ₽ (U+20BD) и типографский минус (U+2212) — рендерит «?».
   Эти символы выводим в PT Sans, цифры остаются моноширинными. */
.rub { font-family: 'PT Sans', sans-serif; font-weight: inherit; }

/* Note about stock */
.tnote { font: 8pt/1.3 'PT Sans', sans-serif; color: #5c6470; padding: 1.2mm 3mm; background: #f6f7f9; border-left: 1pt solid #D32027; border-radius: 0 1mm 1mm 0; margin-bottom: 1.8mm; }
.tnote b { color: #0f1419; }

/* Totals */
.totals { display: table; width: 100%; margin-bottom: 1.8mm; }
.totals .row { display: table-row; }
.totals .words { display: table-cell; padding: 2mm 3.5mm; background: #fafbfc; border: 0.5pt solid #d8dce3; border-radius: 1.5mm; font: 9pt/1.38 'PT Sans', sans-serif; width: 58%; vertical-align: top; }
.totals .words .lbl { font: bold 8pt/1 'PT Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; margin-bottom: 1mm; }
.totals .words .v b { font-weight: bold; }
.totals .right { display: table-cell; padding-left: 6mm; vertical-align: top; }
.totals table { width: 100%; border-collapse: collapse; font: 9.5pt/1.35 'PT Sans', sans-serif; }
.totals table td { padding: 0.9mm 3mm; }
.totals table td:first-child { color: #5c6470; }
.totals table td:last-child { font-family: 'PT Mono', monospace; text-align: right; font-weight: normal; }
.totals tr.disc td { color: #0f6e3a; }
.totals tr.disc td:last-child { font-weight: bold; }
.totals tr.vat td { color: #5c6470; font-size: 9pt; }
.totals tr.grand td { background: #0f1419; color: #fff; font: bold 11pt/1.3 'PT Sans', sans-serif; padding: 2.2mm 3mm; }
.totals tr.grand td:last-child { color: #fff; font-family: 'PT Mono', monospace; }

/* Conditions */
.cond { width: 100%; border-collapse: collapse; margin-bottom: 1.8mm; font: 8.5pt/1.32 'PT Sans', sans-serif; }
.cond .col { padding-right: 6mm; vertical-align: top; width: 50%; }
.cond .col:last-child { padding-right: 0; padding-left: 6mm; }
.cond h3 { margin: 0 0 1mm; font: bold 8pt/1 'PT Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; }
.cond p { margin: 0 0 1mm; }
.cond .warn { color: #D32027; font-weight: bold; }
.cond .scale { width: 100%; border-collapse: collapse; }
.cond .scale > tr > td, .cond .scale td { vertical-align: top; padding: 0; width: 50%; }
.cond ul.disc { margin: 0; padding-left: 4mm; font-size: 8pt; line-height: 1.3; }
.cond ul.disc li { margin-bottom: 0; }
.cond ul.disc li b.cf { font-weight: normal; color: #5c6470; }
.cond ul.disc .pct { color: #D32027; font-weight: bold; font-family: 'PT Mono', monospace; }
.cond .foot { font-size: 7pt; color: #5c6470; margin-top: 1mm; line-height: 1.25; }

/* Signature */
.sign { width: 100%; border-collapse: collapse; margin-top: 1.5mm; }
.sign td.sigtop { border-top: 0.4pt solid #d8dce3; padding-top: 3mm; }
.sign .left { vertical-align: bottom; font: 8.5pt/1.45 'PT Sans', sans-serif; color: #5c6470; padding-right: 6mm; width: 50%; }
.sign .left b { color: #0f1419; font-weight: bold; }
.sign .edo { margin-top: 2mm; padding: 1.5mm 2.5mm; background: #f6f7f9; border-radius: 1mm; font-size: 7.5pt; }
.sign .edo .id { font-family: 'PT Mono', monospace; color: #222; font-size: 7pt; word-break: break-all; }
.sign .right { vertical-align: bottom; text-align: right; }
.sign .role { font: 9pt/1.3 'PT Sans', sans-serif; color: #5c6470; margin-bottom: 2mm; }

/* Зона подписи: подпись + печать наложены поверх линии (position:absolute,
   только top-офсеты — dompdf криво считает bottom). */
.sigwrap { position: relative; height: 15mm; }
.sigwrap .sig { position: absolute; top: 0; right: 4mm; width: 32mm; height: auto; }
.sigwrap .stamp { position: absolute; top: 0; right: 26mm; width: 21mm; height: auto; }
.sigwrap .line { position: absolute; top: 11mm; left: 0; right: 0; border-bottom: 0.5pt solid #0f1419; padding-bottom: 0.5mm; }
.sigwrap .line .label { float: left; color: #5c6470; font-size: 8pt; }
.sigwrap .line .who { float: right; color: #0f1419; font-weight: bold; font-size: 9.5pt; }
.sigwrap .caption { position: absolute; top: 13mm; left: 0; right: 0; text-align: right; font: 7.5pt/1.2 'PT Sans', sans-serif; color: #9aa0a8; }

.runfoot { margin-top: 1.5mm; padding-top: 1mm; border-top: 0.4pt solid #d8dce3; font: 7.5pt/1.3 'PT Sans', sans-serif; color: #5c6470; }
.runfoot table { width: 100%; }
.runfoot td:last-child { font-family: 'PT Mono', monospace; text-align: right; }
</style>
</head>
<body>

<div class="sheet">

  <div class="notice">
    <p><span class="warn">Работаем с НДС, на Общей Схеме Налогообложения.</span> Продажа оформляется Единым Передаточным Документом (УПД). См. Приложение №1 к постановлению Правительства РФ от 26.12.2011 № 1137.</p>
    <p class="warn">Внимание! Не является основанием для платежа и не резервирует позиции на складе. Для оплаты и резервирования запрашивайте счёт.</p>
  </div>

  <div class="head">
    <div class="row">
      @if($logoPath && file_exists($logoPath))
        <div class="logo"><img src="{{ $logoPath }}" alt="{{ $company['short_name'] }}"></div>
      @else
        <div class="logo"></div>
      @endif
      <div class="brand">
        {{ $company['short_name'] }}
        <small>{{ $company['brand_tagline'] }}</small>
      </div>
      <div class="title">
        <h1>Коммерческое предложение</h1>
        <div class="num">№ <span class="code">{{ $q->internal_code }}</span></div>
        <div class="date">от {{ $issueDateRu }}</div>
        <span class="stripe"></span>
      </div>
    </div>
  </div>

  <div class="parties">
    <div class="p">
      <span class="lbl">Исполнитель</span>
      <span class="val"><b>{{ $company['legal_name'] }}</b>, ИНН <span class="mono">{{ $company['inn'] }}</span>, КПП <span class="mono">{{ $company['kpp'] }}</span>. {{ $company['postal_code'] }}, {{ $company['address'] }}. Тел.: <span class="mono">{{ $company['phone'] }}</span>, {{ $company['email'] }}</span>
    </div>
    @if($q->recipient_name)
      @php
          $recipientLine = '';
          if ($q->recipient_inn) {
              $recipientLine .= ', ИНН <span class="mono">' . e($q->recipient_inn) . '</span>';
          }
          if ($q->recipient_address) {
              $recipientLine .= '. ' . e($q->recipient_address);
          }
      @endphp
      <div class="p">
        <span class="lbl">Заказчик</span>
        <span class="val"><b>{{ $q->recipient_name }}</b>{!! $recipientLine !!}</span>
      </div>
    @endif
    @if($q->responsibleUser)
      @php
          $resp = $q->responsibleUser;
          $respLine = '';
          if ($resp->phone) {
              $respLine .= ', тел. <span class="mono">' . e($resp->phone) . '</span>';
              if ($resp->phone_extension) {
                  $respLine .= ' доб. ' . e($resp->phone_extension);
              }
          }
          if ($resp->email) {
              $respLine .= ', ' . e($resp->email);
          }
      @endphp
      <div class="p">
        <span class="lbl">Ответственный</span>
        <span class="val"><b>{{ $resp->name }}</b>{!! $respLine !!}</span>
      </div>
    @endif
  </div>

  @if($requestItemsForSubj->isNotEmpty())
    <div class="subj">
      <span class="lbl">Содержание запроса ({{ $requestItemsForSubj->count() }} {{ $requestItemsForSubj->count() === 1 ? 'позиция' : ($requestItemsForSubj->count() < 5 ? 'позиции' : 'позиций') }}):</span>
      @foreach($requestItemsForSubj as $idx => $ri)
        <span class="it"><b>{{ $idx + 1 }}.</b> {{ \Illuminate\Support\Str::limit($ri->parsed_name, 70) }}@if($ri->parsed_qty) — {{ rtrim(rtrim((string)$ri->parsed_qty, '0'), '.') }} {{ rtrim($ri->parsed_unit ?: 'шт', '.') }}@endif</span>@if(! $loop->last) <span class="sep">·</span> @endif
      @endforeach
    </div>
  @endif

  <table class="items">
    <thead>
      <tr>
        <th class="r" style="width:8mm">№</th>
        <th>Наименование и артикул</th>
        <th style="width:22mm">Срок</th>
        <th class="r" style="width:14mm">Кол-во</th>
        <th class="r" style="width:30mm">Цена со скидкой</th>
        <th class="r" style="width:27mm">Сумма (НДС в т. ч.)</th>
      </tr>
    </thead>
    <tbody>
      @foreach($q->items as $idx => $item)
        @php
          $effDisc = $item->discount_percent !== null ? (float) $item->discount_percent : (float) $q->discount_percent;
          $rowClass = $idx % 2 === 1 ? 'even' : '';
        @endphp
        <tr class="{{ $rowClass }}">
          <td class="num">{{ $item->position }}</td>
          <td class="name">
            <div class="t">{{ $item->snapshot_name }}</div>
            @if($item->snapshot_sku)
              <div class="art">{{ $item->snapshot_sku }}</div>
            @endif
          </td>
          <td class="term">
            @if($item->delivery_text)
              {{ $item->delivery_text }}
            @elseif($item->catalog_in_stock)
              Со склада
            @else
              Под заказ
            @endif
            @if($item->catalog_lead_time_days)<span class="s">≈ {{ (int) ceil($item->catalog_lead_time_days / 7) }} нед</span>@endif
          </td>
          <td class="qty">{{ rtrim(rtrim((string) $item->qty, '0'), '.') }} <small>{{ $item->unit }}</small></td>
          <td class="pricebox">
            <span class="now">{{ number_format((float) $item->final_unit_price, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span></span>
            @php
                $showWasDisc = $effDisc > 0 && (float) $item->catalog_unit_price > (float) $item->final_unit_price;
            @endphp
            @if($showWasDisc)
              <span class="wasline"><span class="was">{{ number_format((float) $item->catalog_unit_price, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span></span> <span class="disc"><span class="rub">−</span>{{ rtrim(rtrim(number_format($effDisc, 2, ',', ''), '0'), ',') }}%</span></span>
            @endif
          </td>
          <td class="sum">
            {{ number_format((float) $item->line_total, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span>
            <span class="vat">НДС {{ number_format((float) $item->vat_amount, 2, ',', "\u{00A0}") }} ₽</span>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="tnote">
    Информация о наличии указана по состоянию склада на <b>{{ $stockStamp }}</b>. Цены и условия предложения <b>актуальны до {{ $validUntilShort }}</b> (неизменность стоимости гарантируется <b>{{ $q->valid_days }} дней</b> с даты предложения).
  </div>

  <div class="totals">
    <div class="row">
      <div class="words">
        <div class="lbl">Сумма прописью</div>
        <div class="v">{{ $totalInWords }}</div>
      </div>
      <div class="right">
        <table>
          <tr><td>Итого</td><td>{{ number_format((float) $q->subtotal, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span></td></tr>
          @php $hasDisc = (float) $q->discount_amount > 0; @endphp
          @if($hasDisc)
            <tr class="disc"><td>Скидка {{ rtrim(rtrim(number_format((float) $q->discount_percent, 2, ',', ''), '0'), ',') }} %</td><td><span class="rub">−</span>&nbsp;{{ number_format((float) $q->discount_amount, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span></td></tr>
            <tr><td>Итого со скидкой</td><td>{{ number_format((float) $q->total, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span></td></tr>
          @endif
          <tr class="vat"><td>в т. ч. НДС {{ rtrim(rtrim(number_format((float) $q->vat_rate, 2, ',', ''), '0'), ',') }} %</td><td>{{ number_format((float) $q->vat_amount, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span></td></tr>
          <tr class="grand"><td>К оплате</td><td>{{ number_format((float) $q->total, 2, ',', "\u{00A0}") }}&nbsp;<span class="rub">₽</span></td></tr>
        </table>
      </div>
    </div>
  </div>

  <table class="cond">
    <tr>
    <td class="col">
      <h3>Условия резервирования</h3>
      <p><span class="warn">Важно.</span> Резервирование позиций — только на основании счёта, действительно в течение <b>5 рабочих дней</b> с момента выставления счёта до оплаты.</p>
    </td>
    <td class="col">
      <h3>Шкала постоянных скидок</h3>
      <table class="scale"><tr>
        <td>
          <ul class="disc">
            <li>3 мес. от 300 000 ₽ — <span class="pct">5 %</span></li>
            <li>6 мес. от 500 000 ₽ — <span class="pct">10 %</span></li>
            <li>1 год от 1 000 000 ₽ — <span class="pct">15 %</span></li>
          </ul>
        </td>
        <td>
          <ul class="disc">
            <li>1 год от 2 000 000 ₽ — <span class="pct">17 %</span> <b class="cf">*</b></li>
            <li>1 год от 4 000 000 ₽ — <span class="pct">20 %</span> <b class="cf">*</b></li>
          </ul>
        </td>
      </tr></table>
      <div class="foot"><b>*</b> подтверждаемая: ежегодная проверка суммы закупок. Лимитируемые скидки применяются не ко всем товарам и ограничены минимальной ценой продажи.</div>
    </td>
    </tr>
  </table>

  <table class="sign">
    <tr>
    <td class="left sigtop">
      <b>Работаем по ЭДО (Диадок)</b><br>
      Документооборот через оператора ЭДО без бумажных копий.
      <div class="edo">
        Идентификатор ЭДО:
        <span class="id">{{ $company['edo_id'] }}</span>
      </div>
    </td>
    <td class="right sigtop">
      <div class="role">{{ $company['director_title'] }} {{ $company['legal_name'] }}</div>
      <div class="sigwrap">
        @if($stampPath && file_exists($stampPath))
          <img class="stamp" src="{{ $stampPath }}" alt="М.П.">
        @endif
        @if($signaturePath && file_exists($signaturePath))
          <img class="sig" src="{{ $signaturePath }}" alt="подпись">
        @endif
        <div class="line">
          <span class="label">подпись</span>
          <span class="who">{{ $company['director_name'] }}</span>
        </div>
        <div class="caption">М.П.</div>
      </div>
    </td>
    </tr>
  </table>

  <div class="runfoot">
    <table>
      <tr>
        <td>{{ $company['legal_name'] }} · ИНН {{ $company['inn'] }} · {{ $company['email'] }}</td>
        <td>{{ $q->internal_code }} · v{{ $q->version }}</td>
      </tr>
    </table>
  </div>

</div>

</body>
</html>
