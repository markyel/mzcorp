---
name: marketing-assignments
description: Маркетинг → «Аутсорс-задачи» (?tab=tasks): задачи соседним отделам без доступа в MyLift — инструкция markdown + файлы, выгрузка ZIP/PDF
metadata:
  type: reference
---

Вкладка «📋 Аутсорс-задачи» (до 28.10 называлась «Поручения») в маркетинге (`App\Livewire\Marketing\Assignments`, только admin), сделана 2026-10-28 по просьбе: задачи соседнему отделу (завести <адрес>, страница 404, пресс-кит). У исполнителей НЕТ учёток в MyLift → поручение выгружается: «⭳ Архив» = Инструкция.pdf + Инструкция.html + Материалы/NN_файл; «⭳ PDF».

- Таблицы `marketing_assignments` (brief → instructions markdown, статусы draft/handed/in_progress/done/cancelled, result_notes) и `marketing_assignment_files` (disk local, `marketing/assignments/{id}/`).
- Выгрузка `MarketingAssignmentExportService` (dompdf + PT Sans как в экспорте переписки, Str::markdown с html_input=escape), шаблон `resources/views/marketing/assignment-export.blade.php`.
- «✍ Расписать инструкцию» — `MarketingAssignmentWriter` + `WriteAssignmentPrompt` (только факты постановки, [уточнить]).
- Сайт myzip.ru: самописный PHP 5.6 + Bootstrap 4, Roboto, красный #E94A4B, тёмный #373D53; 404 сейчас — дефолтная страница хостинга (у mylift.ru та же). Цифры сайта (/about/: 17 000 на складе) расходятся с медиапрофилем (30 000+ / 6 000+ в наличии).

Связано с [[press-releases-module]].
