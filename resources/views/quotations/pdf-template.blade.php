<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>{{ $company['legal_name'] }} · КП {{ $q->internal_code }}</title>
<style>
* { box-sizing: border-box; }
@page { size: A4 portrait; margin: 0; }
html, body { margin: 0; padding: 0; background: #fff; font-family: DejaVu Sans, sans-serif; color: #0f1419; }
.sheet { width: 210mm; min-height: 297mm; background: #fff; padding: 14mm 12mm 12mm; font-size: 9.5pt; line-height: 1.4; color: #0f1419; }

/* Top notice */
.notice { font: 8pt/1.4 DejaVu Sans, sans-serif; color: #5c6470; padding-bottom: 3mm; border-bottom: 0.4pt solid #c0c4ca; margin-bottom: 6mm; }
.notice p { margin: 0 0 1mm; }
.notice .warn { color: #0f1419; font-weight: 700; }

/* Header */
.head { display: table; width: 100%; margin-bottom: 6mm; }
.head .row { display: table-row; }
.head .logo { display: table-cell; width: 28mm; vertical-align: middle; }
.head .logo img { width: 28mm; height: auto; display: block; }
.head .brand { display: table-cell; padding-left: 8mm; vertical-align: middle; font: 700 16pt/1.1 DejaVu Sans, sans-serif; }
.head .brand small { display: block; font: 500 8.5pt/1.3 DejaVu Sans, sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.4pt; margin-top: 1mm; font-weight: normal; }
.head .title { display: table-cell; text-align: right; vertical-align: middle; }
.head .title h1 { margin: 0 0 1mm; font: 700 14pt/1.2 DejaVu Sans, sans-serif; color: #0f1419; }
.head .title .num { font: 700 11pt/1.3 DejaVu Sans, sans-serif; }
.head .title .num .code { font-family: 'DejaVu Sans Mono', monospace; color: #D32027; }
.head .title .date { font: 9pt/1.3 DejaVu Sans, sans-serif; color: #5c6470; margin-top: 1mm; }
.head .title .stripe { display: inline-block; height: 1.5pt; background: #D32027; width: 42mm; margin-top: 2mm; }

/* Parties */
.parties { margin-bottom: 5mm; font: 9.5pt/1.5 DejaVu Sans, sans-serif; }
.parties .p { margin-bottom: 2mm; display: table; width: 100%; }
.parties .p .lbl { display: table-cell; width: 32mm; font: 700 8.5pt/1.3 DejaVu Sans, sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.5pt; vertical-align: top; padding-top: 0.5mm; }
.parties .p .val { display: table-cell; color: #0f1419; vertical-align: top; }
.parties .val b { font-weight: 700; }
.parties .val .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 9pt; }

/* Содержание запроса */
.subj { margin-bottom: 4mm; padding: 3mm 4mm; background: #f6f7f9; font: 9.5pt/1.5 DejaVu Sans, sans-serif; }
.subj .lbl { font: 700 8.5pt/1 DejaVu Sans, sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.5pt; margin-bottom: 1.5mm; }
.subj ol { margin: 0; padding-left: 5mm; }
.subj ol li { margin-bottom: 0.5mm; }

/* Items */
.items { width: 100%; border-collapse: collapse; font: 8.5pt/1.35 DejaVu Sans, sans-serif; margin-bottom: 4mm; }
.items thead th { background: #f4f6f9; color: #0f1419; font: 700 8pt/1.25 DejaVu Sans, sans-serif; padding: 2mm 1.6mm; text-align: left; vertical-align: middle; border-bottom: 0.6pt solid #0f1419; border-right: 0.3pt solid #d8dce3; }
.items thead th:last-child { border-right: none; }
.items thead th.r { text-align: right; }
.items tbody td { padding: 2.2mm 1.6mm; border-bottom: 0.4pt solid #e3e6eb; vertical-align: top; font-size: 8.5pt; }
.items tbody tr.even td { background: #fafbfc; }
.items .num { text-align: right; color: #5c6470; font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; }
.items .name .t { font-weight: 700; color: #0f1419; line-height: 1.3; font-size: 9pt; }
.items .name .art { font-family: 'DejaVu Sans Mono', monospace; color: #5c6470; font-size: 8pt; margin-top: 0.6mm; }
.items .term { font-size: 8.5pt; line-height: 1.3; color: #0f1419; }
.items .term .s { display: block; color: #5c6470; font-weight: normal; font-size: 7.5pt; margin-top: 0.4mm; }
.items .actu { font-size: 8.5pt; line-height: 1.3; color: #0f6e3a; font-weight: 500; font-family: 'DejaVu Sans Mono', monospace; }
.items .qty { text-align: right; color: #0f1419; font-weight: 500; }
.items .qty small { color: #5c6470; font-weight: normal; font-size: 7.5pt; margin-left: 1mm; }
.items .pricebox { text-align: right; line-height: 1.2; }
.items .pricebox .now { color: #0f1419; font-weight: 700; font-size: 9.5pt; font-family: 'DejaVu Sans Mono', monospace; display: block; margin-bottom: 0.6mm; }
.items .pricebox .was { color: #9aa0a8; font-family: 'DejaVu Sans Mono', monospace; text-decoration: line-through; font-size: 7.5pt; display: block; }
.items .pricebox .disc { color: #0f6e3a; font-weight: 700; font-size: 7.5pt; display: block; margin-top: 0.2mm; }
.items .sum { text-align: right; font-family: 'DejaVu Sans Mono', monospace; color: #0f1419; font-weight: 700; font-size: 9pt; line-height: 1.25; }
.items .vat { display: block; color: #5c6470; font-weight: normal; font-size: 7pt; margin-top: 0.3mm; font-family: DejaVu Sans, sans-serif; }

/* Note about stock */
.tnote { font: 8pt/1.4 DejaVu Sans, sans-serif; color: #5c6470; padding: 1.5mm 3mm; background: #f6f7f9; border-left: 1pt solid #D32027; margin-bottom: 5mm; }
.tnote b { color: #0f1419; }

/* Totals */
.totals { display: table; width: 100%; margin-bottom: 5mm; }
.totals .row { display: table-row; }
.totals .words { display: table-cell; padding: 3mm 4mm; background: #fafbfc; border: 0.5pt solid #d8dce3; font: 9pt/1.5 DejaVu Sans, sans-serif; width: 60%; vertical-align: top; }
.totals .words .lbl { font: 700 8pt/1 DejaVu Sans, sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.5pt; margin-bottom: 1.5mm; }
.totals .words .v b { font-weight: 700; }
.totals .right { display: table-cell; padding-left: 6mm; vertical-align: top; min-width: 72mm; }
.totals table { width: 100%; border-collapse: collapse; font: 9.5pt/1.35 DejaVu Sans, sans-serif; }
.totals table td { padding: 1.5mm 3mm; }
.totals table td:first-child { color: #5c6470; }
.totals table td:last-child { font-family: 'DejaVu Sans Mono', monospace; text-align: right; font-weight: 500; }
.totals tr.disc td { color: #0f6e3a; }
.totals tr.disc td:last-child { font-weight: 700; }
.totals tr.vat td { color: #5c6470; font-size: 9pt; }
.totals tr.grand td { background: #0f1419; color: #fff; font: 700 11pt/1.3 DejaVu Sans, sans-serif; padding: 3mm; }
.totals tr.grand td:last-child { color: #fff; font-family: 'DejaVu Sans Mono', monospace; }

/* Conditions */
.cond { display: table; width: 100%; margin-bottom: 5mm; font: 8.5pt/1.45 DejaVu Sans, sans-serif; }
.cond .col { display: table-cell; padding-right: 6mm; vertical-align: top; width: 50%; }
.cond .col:last-child { padding-right: 0; padding-left: 6mm; }
.cond h3 { margin: 0 0 1.5mm; font: 700 8pt/1 DejaVu Sans, sans-serif; color: #5c6470; text-transform: uppercase; letter-spacing: 0.5pt; }
.cond p { margin: 0 0 1mm; }
.cond .warn { color: #D32027; font-weight: 700; }
.cond ul.disc { margin: 0; padding-left: 4mm; font-size: 8.5pt; line-height: 1.5; }
.cond ul.disc li { margin-bottom: 0.3mm; }
.cond ul.disc li b { font-weight: 700; color: #0f1419; }
.cond ul.disc .pct { color: #D32027; font-weight: 700; font-family: 'DejaVu Sans Mono', monospace; }
.cond .foot { font-size: 7.5pt; color: #5c6470; margin-top: 1.5mm; line-height: 1.4; }

/* Signature */
.sign { display: table; width: 100%; margin-top: 5mm; padding-top: 4mm; border-top: 0.4pt solid #d8dce3; }
.sign .left { display: table-cell; vertical-align: bottom; font: 8.5pt/1.45 DejaVu Sans, sans-serif; color: #5c6470; padding-right: 8mm; }
.sign .left b { color: #0f1419; font-weight: 700; }
.sign .edo { margin-top: 2mm; padding: 1.5mm 2.5mm; background: #f6f7f9; font-size: 7.5pt; }
.sign .edo .id { font-family: 'DejaVu Sans Mono', monospace; color: #222; font-size: 7pt; word-break: break-all; }
.sign .right { display: table-cell; vertical-align: bottom; text-align: right; min-width: 74mm; }
.sign .role { font: 9pt/1.3 DejaVu Sans, sans-serif; color: #5c6470; margin-bottom: 12mm; }
.sign .line { border-bottom: 0.5pt solid #0f1419; padding-bottom: 0.5mm; }
.sign .line .label { color: #5c6470; font-size: 8pt; }
.sign .line .who { color: #0f1419; font-weight: 700; font-size: 9.5pt; }
.sign .mp { margin-top: 2mm; font: 8pt/1.3 DejaVu Sans, sans-serif; color: #5c6470; }

.runfoot { margin-top: 4mm; padding-top: 2mm; border-top: 0.4pt solid #d8dce3; font: 7.5pt/1.3 DejaVu Sans, sans-serif; color: #5c6470; }
.runfoot table { width: 100%; }
.runfoot td:last-child { font-family: 'DejaVu Sans Mono', monospace; text-align: right; }
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
      <div class="lbl">Содержание запроса</div>
      <ol>
        @foreach($requestItemsForSubj as $ri)
          <li>{{ $ri->parsed_name ?: '—' }} @if($ri->parsed_qty)— {{ rtrim(rtrim((string)$ri->parsed_qty, '0'), '.') }} {{ $ri->parsed_unit ?: 'шт' }}.@endif</li>
        @endforeach
      </ol>
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
