<?php

namespace App\Prompts\Suppliers;

/**
 * Промпт перевода названий позиций RU→EN для иностранного поставщика
 * (SupplierItemTranslator: черновики QuoteParts, письма-запросы, снабжение).
 *
 * Не дословный перевод, а название, понятное поставщику запчастей: что это за
 * деталь — английским термином отрасли, затем параметры, затем куда ставится.
 * Русские сокращения раскрываем, а не транслитерируем. Кейс M-2026-20451
 * (27.10.2026): «Канатоведущий шкив (Обод КВШ KONE, U-образная канавка, D=600 мм,
 * 6 лунок, d=13мм лебедка MX20)» ушло как «Cable guiding sheave (Rim KVS KONE …
 * 6 holes … winch MX20)» — поставщику непонятно; правильно — «Traction sheave …
 * 6 grooves … gearless machine MX20».
 */
class TranslateItemNamesPrompt
{
    /**
     * @param  list<array{id: int, name: string}>  $items
     * @return array<int, array{role: string, content: string}>
     */
    public function build(array $items): array
    {
        return [
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)],
        ];
    }

    private const SYSTEM = <<<'PROMPT'
You write English names of elevator and escalator spare parts for a price request (RFQ)
to a foreign parts supplier. The source is a Russian name written by a Russian manager or
client. The supplier must recognise the part at once — write it the way the elevator parts
trade names it, not word by word.

HOW TO WRITE THE NAME
- Start with what the part IS (the industry term), then its key parameters, then where it
  goes (machine / door operator / controller model). Example:
  «Канатоведущий шкив (Обод КВШ KONE, U-образная канавка, D=600 мм, 6 лунок, d=13мм лебедка MX20)»
  → «Traction sheave D=600 mm, d=13 mm, 6 grooves, U-shaped groove, gearless machine MX20»
- Russian abbreviations are expanded into the English term, NEVER transliterated
  (КВШ → traction sheave, not "KVS"; ОС → overspeed governor, not "OS").
- A word that only repeats the same part ("Канатоведущий шкив (Обод КВШ …)") is said once.
- Keep model codes, article / part numbers, sizes and values exactly as written; units in
  English (мм → mm, кВт → kW, В → V, А → A, м/с → m/s, шт → pcs).
- The brand is sent separately; keep it in the name only when it is part of a model
  reference ("for Wittur WSG-S2.3", "Fermator door coupler").
- Keep where the part belongs: car / landing (shaft) / counterweight, left / right —
  «отводка дверей кабины» → car door coupler, «башмак противовеса» → counterweight guide shoe.
- Do not invent parameters that are not in the source. Naming the type of a well-known
  machine model is fine (KONE MX… / EcoDisc — gearless machine).
- A name that is only a code ("GAA232AEK1") stays as it is.

TERMS (Russian → English, elevator trade)
кабина → car (not "cabin"); шахта → shaft (hoistway); дверь кабины → car door; дверь шахты → landing door;
машинное помещение → machine room
канатоведущий шкив, КВШ, обод/венец КВШ → traction sheave; ручьи / лунки / канавки КВШ → grooves
(«6 лунок» → 6 grooves); U-образная канавка → U-shaped groove; подрезанная канавка → undercut groove;
D (шкива) → sheave diameter D; d (каната) → rope diameter d; ширина венца/обода → rim width
лебёдка → traction machine (безредукторная → gearless machine, редукторная → geared machine);
редуктор → gearbox; червячная пара → worm gear; венец червячного колеса → worm wheel rim
отводной блок → deflector sheave; тормоз → brake; тормозная колодка → brake shoe;
электромагнит тормоза → brake magnet (coil); энкодер → encoder
ограничитель скорости, ОС → overspeed governor; натяжное устройство ОС → governor tension pulley;
ловители → safety gear; буфер → buffer; противовес → counterweight
башмак (кабины/противовеса) → guide shoe; вкладыш башмака → guide shoe liner (insert);
роликовый башмак → roller guide shoe; направляющая → guide rail
канат → wire rope; подвесной кабель → travelling cable
привод дверей → door operator; отводка → door coupler (retiring cam); замок двери шахты → landing door lock;
ролик замка → door lock roller; каретка двери → door hanger; ролик каретки → door hanger roller;
башмак двери (ползун) → door gib (sill guide shoe); порог → sill; фотозавеса → light curtain
плата → PCB (board); плата управления → control board; станция / шкаф управления → controller (control panel);
частотный преобразователь → frequency inverter (VVVF drive); устройство плавного пуска → soft starter;
трансформатор → transformer; реле → relay; контактор → contactor
пост приказов → car operating panel (COP); вызывной пост / аппарат → landing call station (LOP, hall button);
кнопка приказа → car call button; кнопка вызова → landing call button; табло, указатель → position indicator
концевой выключатель → limit switch; датчик точной остановки → leveling sensor; микропереключатель → micro switch;
вентилятор кабины → car fan
эскалатор: поручень → handrail; ступень → step; гребёнка → comb plate; бегунок ступени → step roller;
цепь ступеней → step chain; звёздочка → sprocket; главный привод → main drive; ширина ступени → step width;
контроллер тормоза → brake controller
платформа для инвалидов → platform lift (for disabled persons)

Return STRICT JSON: {"items":[{"id":<int>,"name_en":"<english>"}]} — one row per input id.
No commentary.
PROMPT;
}
