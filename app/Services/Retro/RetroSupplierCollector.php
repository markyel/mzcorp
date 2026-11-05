<?php

namespace App\Services\Retro;

/**
 * Справочник поставщиков из индекса писем: адресаты наших маркированных запросов
 * расценки (RetroAssembler::isSupplierSubject) и авторы ответов на них. Только
 * расчёт, без базы, почты и GPT.
 *
 * Язык — по теме наших запросов: «Price request» — английский, «Запрос расценки» или
 * кириллица — русский. Контактное лицо — чаще всего встречающаяся подпись адреса в
 * письмах. До аварии все адресаты маркированных запросов были в справочнике: CRM
 * отправлял запросы только поставщикам из него. Поэтому сюда попадают и дилеры,
 * которые бывают клиентами; клиентом своей заявки они от этого не перестают быть.
 */
class RetroSupplierCollector
{
    /**
     * @param  array{internal_domains:list<string>, free_mail_domains:list<string>, service_sender_pattern:string}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @param  list<array>  $rows  письма индекса (RetroIndex::load)
     * @return array<string, array{email:string, domain:string, contact:?string, language:string, requests:int, replies:int, first_ts:int, last_ts:int, free_mail:bool}>
     */
    public function collect(array $rows): array
    {
        $out = [];
        $names = [];
        $lang = [];
        foreach ($rows as $row) {
            if (! RetroAssembler::isSupplierSubject($row['subject'])) {
                continue;
            }
            $addresses = $row['outbound']
                ? array_map(fn ($a) => [$a, $row['recipient_names'][$a] ?? null], $row['recipients'])
                : [[$row['from_email'], $row['from_name']]];
            foreach ($addresses as [$address, $name]) {
                $address = mb_strtolower(trim((string) $address));
                if (! $this->isSupplierAddress($address)) {
                    continue;
                }
                $ts = (int) ($row['ts'] ?? 0);
                $out[$address] ??= [
                    'email' => $address, 'domain' => $this->domain($address), 'contact' => null, 'language' => 'ru',
                    'requests' => 0, 'replies' => 0, 'first_ts' => $ts, 'last_ts' => $ts,
                    'free_mail' => in_array($this->domain($address), $this->config['free_mail_domains'], true),
                ];
                $out[$address][$row['outbound'] ? 'requests' : 'replies']++;
                $out[$address]['first_ts'] = min($out[$address]['first_ts'], $ts);
                $out[$address]['last_ts'] = max($out[$address]['last_ts'], $ts);
                $name = trim((string) $name, " \t\"'");
                if ($name !== '' && mb_strtolower($name) !== $address && ! str_contains($name, '@')) {
                    $names[$address][$name] = ($names[$address][$name] ?? 0) + 1;
                }
                if ($row['outbound']) {
                    $l = $this->language($row['subject']);
                    $lang[$address][$l] = ($lang[$address][$l] ?? 0) + 1;
                }
            }
        }
        foreach ($out as $address => &$supplier) {
            if (isset($names[$address])) {
                arsort($names[$address]);
                $supplier['contact'] = array_key_first($names[$address]);
            }
            if (isset($lang[$address])) {
                arsort($lang[$address]);
                $supplier['language'] = array_key_first($lang[$address]);
            }
        }
        unset($supplier);
        ksort($out);

        return $out;
    }

    /** ru или en по теме нашего запроса. */
    public function language(string $subject): string
    {
        if (preg_match('/price request/i', $subject)) {
            return 'en';
        }
        if (preg_match('/запрос расценки|\p{Cyrillic}/u', $subject)) {
            return 'ru';
        }

        return 'en';
    }

    private function isSupplierAddress(string $address): bool
    {
        return str_contains($address, '@')
            && ! in_array($this->domain($address), $this->config['internal_domains'], true)
            && ! preg_match($this->config['service_sender_pattern'], $address);
    }

    private function domain(string $address): string
    {
        return mb_strtolower((string) substr(strrchr($address, '@') ?: '', 1));
    }
}
