<?php

namespace App\Console\Commands;

use App\Models\Kb\ManufacturerBrand;
use App\Models\Request;
use Illuminate\Console\Command;

/**
 * Одноразовая диагностика по конкретной заявке.
 * Выводит сырые поля позиций + quality_assessment_payload + письма треда,
 * чтобы найти источник проблемного brand-resolution.
 *
 * Использование: php artisan inspect:request M-2026-1147
 * После того как разобрались — этот файл можно удалить.
 */
class InspectRequest extends Command
{
    protected $signature = 'inspect:request {code}';

    protected $description = 'Диагностика разбора заявки: позиции, brand, payload, письма треда';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $req = Request::where('internal_code', $code)->first();
        if (! $req) {
            $this->error("Заявка {$code} не найдена");
            return self::FAILURE;
        }

        $this->line("=== Заявка #{$req->id} {$req->internal_code} ===");
        $this->line("subject: {$req->subject}");
        $this->line("client_email: {$req->client_email}");
        $this->line('status: '.(is_object($req->status) ? ($req->status->value ?? get_class($req->status)) : (string) $req->status));
        $this->line('');

        $this->line('=== ПОЗИЦИИ ===');
        foreach ($req->items as $it) {
            $brandName = null;
            if ($it->manufacturer_brand_id) {
                $brandName = ManufacturerBrand::find($it->manufacturer_brand_id)?->name;
            }
            $this->line("--- Позиция #{$it->position} ---");
            $this->line('  parsed_name: '.var_export($it->parsed_name, true));
            $this->line('  parsed_brand: '.var_export($it->parsed_brand, true));
            $this->line('  parsed_article: '.var_export($it->parsed_article, true));
            $this->line('  manufacturer_brand_id: '.var_export($it->manufacturer_brand_id, true));
            $this->line('  manufacturer_brand_name: '.var_export($brandName, true));
            $this->line('  data_source: '.var_export($it->data_source, true));
            $this->line('  equipment_unit_id: '.var_export($it->equipment_unit_id, true));
            $this->line('  identification_category_id: '.var_export($it->identification_category_id, true));
            $this->line('  category: '.var_export($it->category, true));
            $this->line('  image_attachment_id: '.var_export($it->image_attachment_id, true));

            $qa = $it->quality_assessment_payload ?? [];
            if (! empty($qa)) {
                $this->line('  qa.resolved_brand_source: '.var_export($qa['resolved_brand_source'] ?? null, true));
                $this->line('  qa.resolved_brand_id: '.var_export($qa['resolved_brand_id'] ?? null, true));
                $this->line('  qa.available_parameters.lift_brand: '.var_export(($qa['available_parameters']['lift_brand'] ?? null), true));
                $this->line('  qa.available_parameters keys: '.implode(', ', array_keys((array) ($qa['available_parameters'] ?? []))));
                $this->line('  qa.extracted_parameters: '.json_encode($qa['extracted_parameters'] ?? null, JSON_UNESCAPED_UNICODE));
                $this->line('  qa.decision_log: '.json_encode($qa['decision_log'] ?? null, JSON_UNESCAPED_UNICODE));
                $this->line('  qa.photos_attached_by_n8n: '.var_export($qa['photos_attached_by_n8n'] ?? null, true));
                $this->line('  qa keys: '.implode(', ', array_keys((array) $qa)));
            } else {
                $this->line('  qa: <empty>');
            }
            $this->line('');
        }

        $this->line('=== EMAIL THREAD ===');
        foreach ($req->emailMessages()->orderBy('id')->get() as $m) {
            $body = $m->body_text ?? $m->body_plain ?? $m->body ?? '';
            $bodyClean = mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 1500);
            $this->line("--- msg #{$m->id} | {$m->received_at?->format('d.m H:i')} | from {$m->from_email} ---");
            $this->line("  subject: {$m->subject}");
            $this->line('  body: '.$bodyClean);
            $this->line('');
        }

        $this->line('=== ManufacturerBrand «ЩЛЗ» ===');
        ManufacturerBrand::query()
            ->where(function ($q) {
                $q->where('name', 'like', '%ЩЛЗ%')
                    ->orWhere('name', 'like', '%ербин%')
                    ->orWhere('aliases::text', 'like', '%ЩЛЗ%');
            })
            ->get(['id', 'name', 'aliases', 'specialization_tags', 'is_active'])
            ->each(function ($b) {
                $this->line('  brand #'.$b->id.': '.$b->name);
                $this->line('    aliases: '.json_encode($b->aliases, JSON_UNESCAPED_UNICODE));
                $this->line('    specialization_tags: '.json_encode($b->specialization_tags, JSON_UNESCAPED_UNICODE));
                $this->line('    is_active: '.var_export($b->is_active, true));
            });

        $this->line('');
        $this->line('=== Все бренды в БД (name + aliases) — для поиска подстрочного матча ===');
        ManufacturerBrand::active()->get(['id', 'name', 'aliases'])->each(function ($b) {
            $aliases = is_array($b->aliases) ? implode(', ', $b->aliases) : '';
            $this->line("  #{$b->id} | {$b->name} | aliases: {$aliases}");
        });

        return self::SUCCESS;
    }
}
