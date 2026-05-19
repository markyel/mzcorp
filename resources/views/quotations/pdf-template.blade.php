<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>{{ $company['legal_name'] }} · КП {{ $q->internal_code }}</title>
<style>
* { box-sizing: border-box; }
@page { margin: 10mm 10mm 10mm; }
html, body { margin: 0; padding: 0; background: #fff; font-family: 'DejaVu Sans', sans-serif; color: #0f1419; font-size: 7pt; line-height: 1.3; }
/* Контент шириной A4 минус поля @page (210 − 20 = 190мм).
   Без явного width dompdf масштабирует контент под viewport. */
.sheet { width: 190mm; font-size: 7pt; line-height: 1.3; color: #0f1419; }

/* Top notice */
.notice { font: 6pt/1.35 'DejaVu Sans', sans-serif; color: #5c6470; padding-bottom: 2mm; border-bottom: 0.4pt solid #c0c4ca; margin-bottom: 4mm; }
.notice p { margin: 0 0 0.8mm; }
.notice .warn { color: #0f1419; font-weight: 700; }

/* Header */
.head { display: table; width: 100%; margin-bottom: 4mm; }
.head .row { display: table-row; }
.head .logo { display: table-cell; width: 20mm; vertical-align: middle; }
.head .logo img { width: 20mm; height: auto; display: block; }
.head .brand { display: table-cell; padding-left: 5mm; vertical-align: middle; font: 700 13pt/1.1 'DejaVu Sans', sans-serif; }
.head .brand small { display: block; font: 500 6.5pt/1.3 'DejaVu Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.3pt; margin-top: 0.8mm; font-weight: normal; }
.head .title { display: table-cell; text-align: right; vertical-align: middle; }
.head .title h1 { margin: 0 0 0.8mm; font: 700 11pt/1.2 'DejaVu Sans', sans-serif; color: #0f1419; }
.head .title .num { font: 700 9pt/1.3 'DejaVu Sans', sans-serif; }
.head .title .num .code { font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; color: #D32027; }
.head .title .date { font: 7pt/1.3 'DejaVu Sans', sans-serif; color: #5c6470; margin-top: 0.8mm; }
.head .title .stripe { display: inline-block; height: 1.2pt; background: #D32027; width: 34mm; margin-top: 1.5mm; }

/* Parties */
.parties { margin-bottom: 3mm; font: 7pt/1.45 'DejaVu Sans', sans-serif; }
.parties .p { margin-bottom: 1mm; display: table; width: 100%; }
.parties .p .lbl { display: table-cell; width: 26mm; font: 700 6.5pt/1.3 'DejaVu Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; vertical-align: top; padding-top: 0.4mm; }
.parties .p .val { display: table-cell; color: #0f1419; vertical-align: top; }
.parties .val b { font-weight: 700; }
.parties .val .mono { font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; font-size: 6.8pt; }

/* Содержание запроса */
.subj { margin-bottom: 3mm; padding: 2mm 3mm; background: #f6f7f9; font: 7pt/1.4 'DejaVu Sans', sans-serif; }
.subj .lbl { font: 700 6.5pt/1 'DejaVu Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; margin-bottom: 1mm; }
.subj ol { margin: 0; padding-left: 4mm; }
.subj ol li { margin-bottom: 0.3mm; }

/* Items */
.items { width: 100%; border-collapse: collapse; font: 6.5pt/1.3 'DejaVu Sans', sans-serif; margin-bottom: 3mm; }
.items thead th { background: #f4f6f9; color: #0f1419; font: 700 6.5pt/1.2 'DejaVu Sans', sans-serif; padding: 1.5mm 1.2mm; text-align: left; vertical-align: middle; border-bottom: 0.5pt solid #0f1419; border-right: 0.3pt solid #d8dce3; }
.items thead th:last-child { border-right: none; }
.items thead th.r { text-align: right; }
.items tbody td { padding: 1.5mm 1.2mm; border-bottom: 0.3pt solid #e3e6eb; vertical-align: top; font-size: 6.5pt; }
.items tbody tr.even td { background: #fafbfc; }
.items .num { text-align: right; color: #5c6470; font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; font-size: 6pt; }
.items .name .t { font-weight: 700; color: #0f1419; line-height: 1.25; font-size: 7pt; }
.items .name .art { font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; color: #5c6470; font-size: 6pt; margin-top: 0.4mm; }
.items .term { font-size: 6.5pt; line-height: 1.25; color: #0f1419; }
.items .term .s { display: block; color: #5c6470; font-weight: normal; font-size: 5.8pt; margin-top: 0.3mm; }
.items .actu { font-size: 6.5pt; line-height: 1.25; color: #0f6e3a; font-weight: 500; font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; }
.items .qty { text-align: right; color: #0f1419; font-weight: 500; }
.items .qty small { color: #5c6470; font-weight: normal; font-size: 5.8pt; margin-left: 0.5mm; }
.items .pricebox { text-align: right; line-height: 1.15; }
.items .pricebox .now { color: #0f1419; font-weight: 700; font-size: 7.5pt; font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; display: block; margin-bottom: 0.4mm; }
.items .pricebox .was { color: #9aa0a8; font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; text-decoration: line-through; font-size: 5.8pt; display: block; }
.items .pricebox .disc { color: #0f6e3a; font-weight: 700; font-size: 5.8pt; display: block; margin-top: 0.1mm; }
.items .sum { text-align: right; font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; color: #0f1419; font-weight: 700; font-size: 7pt; line-height: 1.2; }
.items .vat { display: block; color: #5c6470; font-weight: normal; font-size: 5.5pt; margin-top: 0.2mm; font-family: 'DejaVu Sans', sans-serif; }

/* Note about stock */
.tnote { font: 6pt/1.35 'DejaVu Sans', sans-serif; color: #5c6470; padding: 1mm 2.5mm; background: #f6f7f9; border-left: 0.8pt solid #D32027; margin-bottom: 3mm; }
.tnote b { color: #0f1419; }

/* Totals */
.totals { display: table; width: 100%; margin-bottom: 3mm; }
.totals .row { display: table-row; }
.totals .words { display: table-cell; padding: 2mm 3mm; background: #fafbfc; border: 0.4pt solid #d8dce3; font: 7pt/1.4 'DejaVu Sans', sans-serif; width: 60%; vertical-align: top; }
.totals .words .lbl { font: 700 6.5pt/1 'DejaVu Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; margin-bottom: 1mm; }
.totals .words .v b { font-weight: 700; }
.totals .right { display: table-cell; padding-left: 4mm; vertical-align: top; min-width: 60mm; }
.totals table { width: 100%; border-collapse: collapse; font: 7.5pt/1.3 'DejaVu Sans', sans-serif; }
.totals table td { padding: 1mm 2.5mm; }
.totals table td:first-child { color: #5c6470; }
.totals table td:last-child { font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; text-align: right; font-weight: 500; }
.totals tr.disc td { color: #0f6e3a; }
.totals tr.disc td:last-child { font-weight: 700; }
.totals tr.vat td { color: #5c6470; font-size: 7pt; }
.totals tr.grand td { background: #0f1419; color: #fff; font: 700 9pt/1.3 'DejaVu Sans', sans-serif; padding: 2mm 2.5mm; }
.totals tr.grand td:last-child { color: #fff; font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; }

/* Conditions */
.cond { display: table; width: 100%; margin-bottom: 3mm; font: 6.5pt/1.4 'DejaVu Sans', sans-serif; }
.cond .col { display: table-cell; padding-right: 5mm; vertical-align: top; width: 50%; }
.cond .col:last-child { padding-right: 0; padding-left: 5mm; }
.cond h3 { margin: 0 0 1mm; font: 700 6.5pt/1 'DejaVu Sans', sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; }
.cond p { margin: 0 0 0.8mm; }
.cond .warn { color: #D32027; font-weight: 700; }
.cond ul.disc { margin: 0; padding-left: 3mm; font-size: 6.5pt; line-height: 1.4; }
.cond ul.disc li { margin-bottom: 0.2mm; }
.cond ul.disc li b { font-weight: 700; color: #0f1419; }
.cond ul.disc .pct { color: #D32027; font-weight: 700; font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; }
.cond .foot { font-size: 5.8pt; color: #5c6470; margin-top: 1mm; line-height: 1.3; }

/* Signature */
.sign { display: table; width: 100%; margin-top: 3mm; padding-top: 3mm; border-top: 0.4pt solid #d8dce3; }
.sign .left { display: table-cell; vertical-align: bottom; font: 6.5pt/1.4 'DejaVu Sans', sans-serif; color: #5c6470; padding-right: 6mm; }
.sign .left b { color: #0f1419; font-weight: 700; }
.sign .edo { margin-top: 1.5mm; padding: 1mm 2mm; background: #f6f7f9; font-size: 5.8pt; }
.sign .edo .id { font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; color: #222; font-size: 5.5pt; word-break: break-all; }
.sign .right { display: table-cell; vertical-align: bottom; text-align: right; min-width: 62mm; }
.sign .role { font: 7pt/1.3 'DejaVu Sans', sans-serif; color: #5c6470; margin-bottom: 8mm; }
.sign .line { border-bottom: 0.4pt solid #0f1419; padding-bottom: 0.4mm; }
.sign .line .label { color: #5c6470; font-size: 6.5pt; }
.sign .line .who { color: #0f1419; font-weight: 700; font-size: 7.5pt; }
.sign .mp { margin-top: 1.5mm; font: 6.5pt/1.3 'DejaVu Sans', sans-serif; color: #5c6470; }

.runfoot { margin-top: 3mm; padding-top: 1.5mm; border-top: 0.4pt solid #d8dce3; font: 5.8pt/1.3 'DejaVu Sans', sans-serif; color: #5c6470; }
.runfoot table { width: 100%; }
.runfoot td:last-child { font-family: 'DejaVu Sans Mono', 'DejaVu Sans', monospace; text-align: right; }
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
    @php
        // При большом числе позиций (>5) разворачиваем в одну строку с
        // нумерацией через '|' — иначе блок «Содержание запроса» занимает
        // полстраницы. До 5 позиций — обычный <ol> для читаемости.
        $manyItems = $requestItemsForSubj->count() > 5;
    @endphp
    <div class="subj">
      <div class="lbl">Содержание запроса ({{ $requestItemsForSubj->count() }} {{ $requestItemsForSubj->count() === 1 ? 'позиция' : ($requestItemsForSubj->count() < 5 ? 'позиции' : 'позиций') }})</div>
      @if($manyItems)
        <div style="font-size: 6.5pt; line-height: 1.4; color: #0f1419;">
          @foreach($requestItemsForSubj as $idx => $ri)
            <span style="white-space: nowrap;"><b>{{ $idx + 1 }}.</b> {{ \Illuminate\Support\Str::limit($ri->parsed_name, 60) }}@if($ri->parsed_qty) — {{ rtrim(rtrim((string)$ri->parsed_qty, '0'), '.') }} {{ rtrim($ri->parsed_unit ?: 'шт', '.') }}@endif</span>@if(! $loop->last) <span style="color:#9aa0a8">|</span> @endif
          @endforeach
        </div>
      @else
        <ol>
          @foreach($requestItemsForSubj as $ri)
            <li>{{ $ri->parsed_name ?: '—' }}@if($ri->parsed_qty) — {{ rtrim(rtrim((string)$ri->parsed_qty, '0'), '.') }} {{ rtrim($ri->parsed_unit ?: 'шт', '.') }}@endif</li>
          @endforeach
        </ol>
      @endif
    </div>
  @endif

  <table class="items">
    <thead>
      <tr>
        <th class="r" style="width:7mm">№</th>
        <th>Наименование, характеристика, артикул</th>
        <th style="width:20mm">Срок</th>
        <th style="width:16mm">Актуально до</th>
        <th class="r" style="width:13mm">Кол-во</th>
        <th class="r" style="width:28mm">Цена со скидкой</th>
        <th class="r" style="width:25mm">Сумма (НДС в т. ч.)</th>
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
            @if($item->snapshot_sku || $item->snapshot_brand || $item->snapshot_brand_article)
              <div class="art">
                @if($item->snapshot_sku){{ $item->snapshot_sku }}@endif
                @if($item->snapshot_brand) · {{ $item->snapshot_brand }}@endif
                @if($item->snapshot_brand_article) · {{ $item->snapshot_brand_article }}@endif
              </div>
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
          <td class="actu">{{ $validUntilShort }}</td>
          <td class="qty">{{ rtrim(rtrim((string) $item->qty, '0'), '.') }} <small>{{ $item->unit }}</small></td>
          <td class="pricebox">
            <span class="now">{{ number_format((float) $item->final_unit_price, 2, ',', "\u{00A0}") }}&nbsp;₽</span>
            @php
                $showWasDisc = $effDisc > 0 && (float) $item->catalog_unit_price > (float) $item->final_unit_price;
            @endphp
            @if($showWasDisc)
              <span class="was">{{ number_format((float) $item->catalog_unit_price, 2, ',', "\u{00A0}") }}&nbsp;₽</span>
              <span class="disc">скидка −{{ rtrim(rtrim(number_format($effDisc, 2, ',', ''), '0'), ',') }} %</span>
            @endif
          </td>
          <td class="sum">
            {{ number_format((float) $item->line_total, 2, ',', "\u{00A0}") }}&nbsp;₽
            <span class="vat">НДС {{ number_format((float) $item->vat_amount, 2, ',', "\u{00A0}") }} ₽</span>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="tnote">
    Информация о наличии указана по состоянию склада на <b>{{ $stockStamp }}</b>. Гарантируем неизменность стоимости на <b>{{ $q->valid_days }} дней</b> с даты предложения.
  </div>

  <div class="totals">
    <div class="row">
      <div class="words">
        <div class="lbl">Сумма прописью</div>
        <div class="v">{{ $totalInWords }}</div>
      </div>
      <div class="right">
        <table>
          <tr><td>Итого</td><td>{{ number_format((float) $q->subtotal, 2, ',', "\u{00A0}") }}&nbsp;₽</td></tr>
          @php $hasDisc = (float) $q->discount_amount > 0; @endphp
          @if($hasDisc)
            <tr class="disc"><td>Скидка {{ rtrim(rtrim(number_format((float) $q->discount_percent, 2, ',', ''), '0'), ',') }} %</td><td>−&nbsp;{{ number_format((float) $q->discount_amount, 2, ',', "\u{00A0}") }}&nbsp;₽</td></tr>
            <tr><td>Итого со скидкой</td><td>{{ number_format((float) $q->total, 2, ',', "\u{00A0}") }}&nbsp;₽</td></tr>
          @endif
          <tr class="vat"><td>в т. ч. НДС {{ rtrim(rtrim(number_format((float) $q->vat_rate, 2, ',', ''), '0'), ',') }} %</td><td>{{ number_format((float) $q->vat_amount, 2, ',', "\u{00A0}") }}&nbsp;₽</td></tr>
          <tr class="grand"><td>К оплате</td><td>{{ number_format((float) $q->total, 2, ',', "\u{00A0}") }}&nbsp;₽</td></tr>
        </table>
      </div>
    </div>
  </div>

  <div class="cond">
    <div class="col">
      <h3>Условия резервирования</h3>
      <p><span class="warn">Важно.</span> Резервирование позиций — только на основании счёта, действительно в течение <b>5 рабочих дней</b> с момента выставления счёта до оплаты.</p>
    </div>
    <div class="col">
      <h3>Шкала постоянных скидок</h3>
      <ul class="disc">
        <li>3 мес. от 300 000 ₽ — <span class="pct">5 %</span></li>
        <li>6 мес. от 500 000 ₽ — <span class="pct">10 %</span></li>
        <li>1 год от 1 000 000 ₽ — <span class="pct">15 %</span></li>
        <li>1 год от 2 000 000 ₽ — <span class="pct">17 %</span> <b style="color:#5c6470;font-weight:normal">подтверждаемая *</b></li>
        <li>1 год от 4 000 000 ₽ — <span class="pct">20 %</span> <b style="color:#5c6470;font-weight:normal">подтверждаемая *</b></li>
      </ul>
      <div class="foot"><b>*</b> ежегодная проверка суммы закупок. Лимитируемые скидки применяются не ко всем товарам и ограничены минимальной ценой продажи.</div>
    </div>
  </div>

  <div class="sign">
    <div class="left">
      <b>Работаем по ЭДО (Диадок)</b><br>
      Документооборот через оператора ЭДО без бумажных копий.
      <div class="edo">
        Идентификатор ЭДО:
        <span class="id">{{ $company['edo_id'] }}</span>
      </div>
    </div>
    <div class="right">
      <div class="role">{{ $company['director_title'] }} {{ $company['legal_name'] }}</div>
      <div class="line">
        <span class="label">подпись</span>
        <span class="who" style="float:right">{{ $company['director_name'] }}</span>
      </div>
      <div class="mp">М.П.</div>
    </div>
  </div>

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
