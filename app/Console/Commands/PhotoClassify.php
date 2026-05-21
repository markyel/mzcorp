<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use App\Models\RequestItem;
use App\Services\Kb\PhotoSlotClassifierService;
use Illuminate\Console\Command;

/**
 * Vision-классификация фоток для позиции по KB photo-slot'ам.
 *
 * Запускает PhotoSlotClassifierService::classifyForItem() на конкретный
 * RequestItem и выводит результат. БД меняется (записывает метаданные в
 * email_attachments + extracted_parameters[photo_*]=true в позиции).
 *
 * Использование:
 *   php artisan photo:classify {item_id}
 */
class PhotoClassify extends Command
{
    protected $signature = 'photo:classify {item_id}';

    protected $description = 'Vision-классификация фоток для позиции по KB photo-slot'.'ам';

    public function handle(PhotoSlotClassifierService $service): int
    {
        $itemId = (int) $this->argument('item_id');
        $item = RequestItem::with('kbCategory')->find($itemId);
        if (! $item) {
            $this->error("Позиция #{$itemId} не найдена");
            return self::FAILURE;
        }

        $this->line('=== Позиция #'.$item->id.' (poz='.$item->position.') ===');
        $this->line('  parsed_name: '.var_export($item->parsed_name, true));
        $this->line('  parsed_brand: '.var_export($item->parsed_brand, true));
        $this->line('  identification_category_id: '.var_export($item->identification_category_id, true));
        $this->line('  kbCategory: '.var_export($item->kbCategory?->name, true));
        $this->line('');

        $this->line('Запуск PhotoSlotClassifierService...');
        $result = $service->classifyForItem($item);
        $this->line('');
        $this->line('--- Результат ---');
        $this->line('  considered_photos: '.$result['considered_photos']);
        $this->line('  matched: '.$result['matched']);
        $this->line('  slugs_covered: '.$result['slugs_covered']);
        $this->line('');

        // Покажем что лежит в payload и в attachments.
        $fresh = $item->fresh();
        $payload = $fresh->quality_assessment_payload ?? [];
        $extracted = $payload['extracted_parameters'] ?? [];
        $photoSlugs = array_filter(array_keys($extracted), fn ($k) => str_starts_with($k, 'photo_'));
        if ($photoSlugs) {
            $this->line('extracted_parameters[photo_*]:');
            foreach ($photoSlugs as $slug) {
                $this->line('  '.$slug.' = '.var_export($extracted[$slug], true));
            }
        } else {
            $this->line('extracted_parameters[photo_*]: (пусто)');
        }
        $this->line('');

        // Метаданные по attachment'ам.
        $msgIds = \App\Models\EmailMessage::where('related_request_id', $item->request_id)->pluck('id');
        $atts = EmailAttachment::whereIn('email_message_id', $msgIds)->get();
        $this->line('Метаданные attachments ('.$atts->count().'):');
        foreach ($atts as $a) {
            $meta = is_array($a->metadata) ? $a->metadata : [];
            $candidates = $meta['kb_slot_candidates'] ?? [];
            $forMe = array_values(array_filter(is_array($candidates) ? $candidates : [],
                fn ($c) => is_array($c) && (int) ($c['request_item_id'] ?? 0) === $item->id));
            if (empty($forMe)) {
                continue;
            }
            $this->line('  attachment #'.$a->id.' '.$a->filename);
            foreach ($forMe as $c) {
                $this->line('    → '.($c['slug'] ?: '(null)')
                    .' | status='.($c['status'] ?? '?')
                    .' | confidence='.($c['confidence'] ?? '?')
                    .' | '.($c['description'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
