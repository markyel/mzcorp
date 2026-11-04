<?php

namespace App\Services\Retro;

/**
 * Чтение индекса писем (см. RetroScanner) в строки для RetroAssembler.
 */
class RetroIndex
{
    public function __construct(
        private readonly string $path,
        private readonly int $year,
        private readonly int $maxNumber,
    ) {}

    /** Ящики, у которых есть индекс. */
    public function mailboxes(): array
    {
        $out = [];
        foreach (glob(rtrim($this->path, '/').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $out[] = basename($dir);
        }

        return $out;
    }

    /** @return list<array> */
    public function load(?array $only = null): array
    {
        $rows = [];
        foreach ($this->mailboxes() as $box) {
            if ($only !== null && ! in_array($box, $only, true)) {
                continue;
            }
            foreach (glob(rtrim($this->path, '/').'/'.$box.'/*.jsonl') ?: [] as $file) {
                $handle = fopen($file, 'r');
                $seen = [];
                while (($line = fgets($handle)) !== false) {
                    $raw = json_decode($line, true);
                    if (! is_array($raw) || isset($seen[$raw['uid'] ?? 0])) {
                        continue;
                    }
                    $seen[$raw['uid']] = true;
                    $rows[] = self::normalize($raw, $box, $this->year, $this->maxNumber);
                }
                fclose($handle);
            }
        }

        return $rows;
    }

    /** Непрочитанные на сервере UID: папка → UID. */
    public function unseen(string $box): array
    {
        $file = rtrim($this->path, '/').'/'.$box.'/folders.json';
        if (! is_file($file)) {
            return [];
        }
        $meta = json_decode((string) file_get_contents($file), true) ?: [];

        return array_map(fn ($f) => array_flip($f['unseen'] ?? []), $meta);
    }

    public static function normalize(array $raw, string $box, int $year, int $maxNumber): array
    {
        $from = RetroMailParser::addresses((string) ($raw['from'] ?? ''))[0] ?? ['email' => '', 'name' => null];
        $to = RetroMailParser::addresses((string) ($raw['to'] ?? ''));
        $cc = RetroMailParser::addresses((string) ($raw['cc'] ?? ''));
        $recipients = [];
        $names = [];
        foreach (array_merge($to, $cc) as $a) {
            $recipients[] = $a['email'];
            $names[$a['email']] = $a['name'];
        }
        $folder = (string) $raw['folder'];
        $mid = RetroMailParser::ids((string) ($raw['mid'] ?? ''))[0] ?? null;

        return [
            'box' => $box,
            'folder' => $folder,
            'uid' => (int) $raw['uid'],
            'ts' => RetroMailParser::timestamp($raw['date'] ?? null, $raw['idate'] ?? null),
            'subject' => (string) ($raw['subject'] ?? ''),
            'from_email' => $from['email'],
            'from_name' => $from['name'],
            'to' => $to,
            'cc' => $cc,
            'recipients' => array_values(array_unique($recipients)),
            'recipient_names' => $names,
            'mid' => $mid,
            'in_reply_to' => RetroMailParser::ids((string) ($raw['irt'] ?? ''))[0] ?? null,
            'references' => RetroMailParser::ids((string) ($raw['refs'] ?? '')),
            'subject_numbers' => self::numbers($raw['codes'] ?? [], $year, $maxNumber),
            'footer_numbers' => self::numbers($raw['foot'] ?? [], $year, $maxNumber),
            'names' => array_values(array_filter(
                array_map(fn ($n) => RetroMailParser::attachmentName((string) $n), $raw['names'] ?? []),
                fn ($n) => ! RetroMailParser::isOwnInlineAsset($n),
            )),
            'outbound' => $folder === 'Sent' || $from['email'] === mb_strtolower($box),
        ];
    }

    private static function numbers(array $codes, int $year, int $maxNumber): array
    {
        $out = [];
        foreach ($codes as $code) {
            if (preg_match('/^M-(\d{4})-(\d+)$/', (string) $code, $m) && (int) $m[1] === $year && (int) $m[2] >= 1 && (int) $m[2] <= $maxNumber) {
                $out[] = (int) $m[2];
            }
        }

        return array_values(array_unique($out));
    }
}
