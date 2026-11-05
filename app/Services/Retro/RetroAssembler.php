<?php

namespace App\Services\Retro;

use App\Enums\ClosedLostReason;
use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;

/**
 * Собирает заявки из индекса писем. Только расчёт: без базы и почты, поэтому
 * правила можно проверять тестами и пересобирать за минуты.
 *
 * Порядок: номер письма берётся из его подвала или темы, письмо без номера
 * наследует его от ближайшего письма цепочки (сначала вверх, потом вниз).
 * Переписка заявки, в которой нет клиента, считается перепиской с поставщиком.
 */
class RetroAssembler
{
    /** @var array<string, string> */
    private array $parent = [];

    /**
     * @param  array{internal_domains:list<string>, free_mail_domains:list<string>, service_sender_pattern:string,
     *     supplier_min_requests:int, max_legacy_number:int, year:int, idle_close_days:int, mz_folder_owners:array<string,string>}  $config
     * @param  list<string>  $handlerEmails  Ящики сотрудников, которые ведут заявки: менеджеры и РОП.
     */
    public function __construct(
        private readonly array $config,
        private readonly array $handlerEmails,
        private readonly int $now,
    ) {}

    /**
     * @param  list<array>  $rows  Письма индекса (RetroIndex::load), нумеруются по позиции.
     * @return array{requests:array<int, array>, rows:list<array>, stats:array<string, int>}
     */
    public function assemble(array $rows): array
    {
        $stats = ['rows' => count($rows), 'multi_code_rows' => 0, 'no_client' => 0];
        $numberByMid = [];
        $numbers = [];

        usort($rows, fn ($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
        foreach ($rows as $i => &$row) {
            $row['i'] = $i;
            $row['number'] = $this->ownNumber($row, $stats);
            $ids = array_merge($row['mid'] !== null ? [$row['mid']] : [], $row['in_reply_to'] !== null ? [$row['in_reply_to']] : [], $row['references']);
            foreach (array_slice($ids, 1) as $id) {
                $this->union($ids[0], $id);
            }
            if ($row['number'] !== null && $row['mid'] !== null && ! isset($numberByMid[$row['mid']])) {
                $numberByMid[$row['mid']] = $row['number'];
            }
        }
        unset($row);

        // Несколько проходов: письмо может получить номер через соседа по цепочке,
        // когда два ответа на одно письмо и номер есть только у одного из них.
        for ($pass = 0; $pass < 6; $pass++) {
            $changed = $this->inheritFromParents($rows, $numberByMid);
            $changed += $this->inheritFromReplies($rows, $numberByMid);
            if ($changed === 0) {
                break;
            }
        }

        $groups = [];
        foreach ($rows as $i => $row) {
            if ($row['number'] !== null) {
                $groups[$row['number']][] = $i;
            }
        }
        $stats['unassigned_rows'] = count($rows) - array_sum(array_map('count', $groups));

        $suppliers = $this->likelySupplierAddresses($rows, $groups);

        $requests = [];
        foreach ($groups as $number => $indexes) {
            $requests[$number] = $this->buildRequest($number, $indexes, $rows, $suppliers, $stats);
        }
        ksort($requests);

        return ['requests' => $requests, 'rows' => $rows, 'stats' => $stats];
    }

    /** Номер ближайшего письма, на которое отвечает это. */
    private function inheritFromParents(array &$rows, array &$numberByMid): int
    {
        $changed = 0;
        foreach ($rows as $i => $row) {
            if ($row['number'] !== null) {
                continue;
            }
            $number = $row['mid'] !== null ? ($numberByMid[$row['mid']] ?? null) : null;
            $chain = array_reverse($row['references']);
            if ($row['in_reply_to'] !== null) {
                array_unshift($chain, $row['in_reply_to']);
            }
            foreach ($chain as $id) {
                if ($number !== null) {
                    break;
                }
                $number = $numberByMid[$id] ?? null;
            }
            if ($number !== null) {
                $rows[$i]['number'] = $number;
                $changed++;
                if ($row['mid'] !== null) {
                    $numberByMid[$row['mid']] ??= $number;
                }
            }
        }

        return $changed;
    }

    /** Письмо, на которое позже ответили с номером. Письма идут по времени, поэтому побеждает самый ранний ответ. */
    private function inheritFromReplies(array &$rows, array &$numberByMid): int
    {
        foreach ($rows as $row) {
            if ($row['number'] === null) {
                continue;
            }
            foreach (array_merge($row['in_reply_to'] !== null ? [$row['in_reply_to']] : [], array_reverse($row['references'])) as $id) {
                $numberByMid[$id] ??= $row['number'];
            }
        }
        $changed = 0;
        foreach ($rows as $i => $row) {
            if ($row['number'] === null && $row['mid'] !== null && isset($numberByMid[$row['mid']])) {
                $rows[$i]['number'] = $numberByMid[$row['mid']];
                $changed++;
            }
        }

        return $changed;
    }

    private function ownNumber(array $row, array &$stats): ?int
    {
        $subject = $row['subject_numbers'];
        $footer = $row['footer_numbers'];
        $all = array_values(array_unique(array_merge($subject, $footer)));
        if ($all === []) {
            return null;
        }
        if (count($all) > 1) {
            $stats['multi_code_rows']++;
        }
        // Подвал цитаты может быть от другой заявки: сначала номер, который есть и в теме, и в подвале.
        foreach ($subject as $n) {
            if (in_array($n, $footer, true)) {
                return $n;
            }
        }

        return $footer[0] ?? $subject[0];
    }

    /**
     * Получатели многих заявок, которые ни разу не получали наш КП или счёт:
     * скорее всего поставщики, клиентом заявки они не становятся.
     */
    private function likelySupplierAddresses(array $rows, array $groups): array
    {
        $requestsByAddress = [];
        $gotDocument = [];
        foreach ($groups as $number => $indexes) {
            foreach ($indexes as $i) {
                $row = $rows[$i];
                if (! $row['outbound']) {
                    continue;
                }
                $hasDocument = false;
                foreach ($row['names'] as $name) {
                    if (RetroMailParser::documentFromFilename($name) !== null) {
                        $hasDocument = true;
                    }
                }
                foreach ($this->externalRecipients($row) as $address) {
                    $key = $this->addressKey($address);
                    $requestsByAddress[$key][$number] = true;
                    if ($hasDocument) {
                        $gotDocument[$key] = true;
                    }
                }
            }
        }
        $out = [];
        foreach ($requestsByAddress as $key => $numbers) {
            if (! isset($gotDocument[$key]) && count($numbers) >= $this->config['supplier_min_requests']) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    private function buildRequest(int $number, array $indexes, array $rows, array $suppliers, array &$stats): array
    {
        $group = array_map(fn ($i) => $rows[$i], $indexes);
        [$clientEmail, $clientName, $clientFrom] = $this->client($group, $suppliers);
        if ($clientEmail === null) {
            $stats['no_client']++;
        }

        // Цепочки заявки: какая с клиентом, какая с поставщиком.
        $chainExternal = [];
        foreach ($group as $row) {
            $root = $this->rootOf($row);
            foreach (array_merge($this->externalRecipients($row), $this->isExternal($row['from_email']) ? [$row['from_email']] : []) as $address) {
                $chainExternal[$root][$address] = true;
            }
        }
        $supplierChain = [];
        foreach ($chainExternal as $root => $addresses) {
            $supplierChain[$root] = $clientEmail !== null && ! $this->anyMatchesClient(array_keys($addresses), $clientEmail);
        }

        $messages = [];
        $seen = [];
        $firstInbound = null;
        $lastAuthor = null;
        $firstAuthor = null;
        $mzOwner = null;
        $quotes = [];
        $invoices = [];
        $lastClientRow = null;
        $hasReply = false;
        $primaryByMid = [];

        // Основная строка письма — в «Отправленных» автора, остальное копии в других ящиках.
        $ranked = $group;
        usort($ranked, fn ($a, $b) => $this->folderRank($a) <=> $this->folderRank($b));
        foreach ($ranked as $row) {
            if ($row['mid'] !== null) {
                $primaryByMid[$row['mid']] ??= $row['i'];
            }
        }

        foreach ($group as $row) {
            $isSupplier = $supplierChain[$this->rootOf($row)] ?? false;
            $messages[] = [
                'i' => $row['i'],
                'supplier' => $isSupplier,
                'copy_of' => ($row['mid'] !== null && $primaryByMid[$row['mid']] !== $row['i']) ? $primaryByMid[$row['mid']] : null,
            ];
            if (isset($this->config['mz_folder_owners'][$row['folder']])) {
                $mzOwner ??= $this->config['mz_folder_owners'][$row['folder']];
            }
            if ($isSupplier) {
                continue;
            }
            $isCopy = $row['mid'] !== null && $primaryByMid[$row['mid']] !== $row['i'];
            if (! $isCopy) {
                $lastClientRow = $row;
            }
            if (! $row['outbound'] && $firstInbound === null && ! $isCopy) {
                $firstInbound = $row['i'];
            }
            // Отвечать могут и из общего ящика (со 2 ноября отвечали из info@), но ответственным он не становится.
            if ($row['outbound'] && $this->externalRecipients($row) !== []) {
                $hasReply = true;
            }
            if ($row['outbound'] && in_array($row['box'], $this->handlerEmails, true)) {
                $firstAuthor ??= $row['box'];
                $lastAuthor = $row['box'];
            }
            if ($row['outbound']) {
                foreach ($row['names'] as $name) {
                    $document = RetroMailParser::documentFromFilename($name);
                    if ($document === null) {
                        continue;
                    }
                    $entry = $document + ['i' => $row['i'], 'ts' => $row['ts'], 'filename' => $name, 'author' => $row['box']];
                    if ($document['type'] === 'quote') {
                        $quotes[$document['number']] ??= $entry;
                    } else {
                        $invoices[$document['number']] ??= $entry;
                    }
                }
            }
        }

        $first = $group[0];
        $last = $lastClientRow ?? $group[array_key_last($group)];
        $createdTs = $first['ts'] ?? $this->now;
        $lastTs = $last['ts'] ?? $createdTs;
        $assigned = $lastAuthor ?? $mzOwner ?? $firstAuthor;
        $firstAssigned = $mzOwner ?? $firstAuthor ?? $assigned;

        [$status, $peak] = $this->status($invoices, $quotes, $hasReply, $assigned);
        $closed = null;
        $idle = $this->config['idle_close_days'];
        if ($idle > 0 && $this->now - $lastTs > $idle * 86400) {
            $closed = ['reason' => $this->closeReason($status), 'ts' => $lastTs];
            $peak = $this->isFunnelStatus($status) ? $status : $peak;
            $status = RequestStatus::ClosedLost;
        }

        return [
            'number' => $number,
            'code' => sprintf('M-%d-%04d', $this->config['year'], $number),
            'created_ts' => $createdTs,
            'last_ts' => $lastTs,
            'last_type' => $closed !== null ? RequestActivityType::ClosedLost : $this->activityType($last, $quotes, $invoices),
            'subject' => $first['subject'],
            'client_email' => $clientEmail,
            'client_name' => $clientName,
            'client_from_inbound' => $clientFrom,
            'first_message' => $firstInbound ?? $first['i'],
            'assigned' => $assigned,
            'first_assigned' => $firstAssigned,
            'status' => $status,
            'peak' => $peak,
            'closed' => $closed,
            'onec_number' => $quotes !== [] ? (string) array_key_last($quotes) : null,
            'quotes' => array_values($quotes),
            'invoices' => array_values($invoices),
            'messages' => $messages,
        ];
    }

    /** @return array{0:?string, 1:?string, 2:bool} Адрес, имя, найден ли клиент по входящему письму. */
    private function client(array $group, array $suppliers): array
    {
        // Надёжнее всего получатель нашего КП или счёта.
        foreach ($group as $row) {
            if (! $row['outbound']) {
                continue;
            }
            foreach ($row['names'] as $name) {
                if (RetroMailParser::documentFromFilename($name) !== null && ($to = $this->externalRecipients($row)) !== []) {
                    return [$to[0], $row['recipient_names'][$to[0]] ?? null, false];
                }
            }
        }
        foreach ($group as $row) {
            if (! $row['outbound'] && $this->isClientAddress($row['from_email'], $suppliers)) {
                return [$row['from_email'], $row['from_name'], true];
            }
        }
        foreach ($group as $row) {
            if ($row['outbound']) {
                foreach ($this->externalRecipients($row) as $address) {
                    if ($this->isClientAddress($address, $suppliers)) {
                        return [$address, $row['recipient_names'][$address] ?? null, false];
                    }
                }
            }
        }
        // Остались только адреса, похожие на поставщиков: лучше такой клиент, чем никакого.
        foreach ($group as $row) {
            if (! $row['outbound'] && $this->isExternal($row['from_email'])) {
                return [$row['from_email'], $row['from_name'], true];
            }
        }

        return [null, null, false];
    }

    private function status(array $invoices, array $quotes, bool $hasReply, ?string $assigned): array
    {
        return match (true) {
            $invoices !== [] => [RequestStatus::Invoiced, RequestStatus::Invoiced],
            $quotes !== [] => [RequestStatus::Quoted, RequestStatus::Quoted],
            $hasReply => [RequestStatus::InProgress, null],
            $assigned !== null => [RequestStatus::Assigned, null],
            default => [RequestStatus::New, null],
        };
    }

    private function isFunnelStatus(RequestStatus $status): bool
    {
        return in_array($status, [RequestStatus::Invoiced, RequestStatus::Quoted], true);
    }

    private function closeReason(RequestStatus $status): ClosedLostReason
    {
        return match ($status) {
            RequestStatus::Invoiced => ClosedLostReason::InvoiceUnpaid,
            RequestStatus::Quoted => ClosedLostReason::NoClientResponseToQuote,
            RequestStatus::InProgress => ClosedLostReason::NoClientResponseToClarification,
            default => ClosedLostReason::ManualOther,
        };
    }

    private function activityType(array $last, array $quotes, array $invoices): RequestActivityType
    {
        if (! $last['outbound']) {
            return RequestActivityType::ClientReplied;
        }
        foreach ($invoices as $doc) {
            if ($doc['i'] === $last['i']) {
                return RequestActivityType::InvoiceSent;
            }
        }
        foreach ($quotes as $doc) {
            if ($doc['i'] === $last['i']) {
                return RequestActivityType::QuoteSent;
            }
        }

        return RequestActivityType::ManagerReplied;
    }

    private function folderRank(array $row): int
    {
        return match (true) {
            $row['folder'] === 'Sent' => 0,
            str_starts_with($row['folder'], 'MZ|') => 1,
            $row['folder'] === 'INBOX' => 2,
            default => 3,
        };
    }

    private function externalRecipients(array $row): array
    {
        return array_values(array_filter($row['recipients'], fn ($a) => $this->isExternal($a)));
    }

    private function isExternal(string $address): bool
    {
        return $address !== '' && ! in_array($this->domain($address), $this->config['internal_domains'], true);
    }

    private function isClientAddress(string $address, array $suppliers): bool
    {
        return $this->isExternal($address)
            && ! preg_match($this->config['service_sender_pattern'], $address)
            && ! isset($suppliers[$this->addressKey($address)]);
    }

    private function anyMatchesClient(array $addresses, string $client): bool
    {
        $key = $this->addressKey($client);
        foreach ($addresses as $address) {
            if ($this->addressKey((string) $address) === $key) {
                return true;
            }
        }

        return false;
    }

    /** Один клиент — один домен, кроме бесплатной почты: там только точный адрес. */
    private function addressKey(string $address): string
    {
        $domain = $this->domain($address);

        return in_array($domain, $this->config['free_mail_domains'], true) ? $address : '@'.$domain;
    }

    private function domain(string $address): string
    {
        return mb_strtolower((string) substr(strrchr($address, '@') ?: '', 1));
    }

    private function rootOf(array $row): string
    {
        $id = $row['mid'] ?? $row['in_reply_to'] ?? ($row['references'][0] ?? null);

        return $id !== null ? $this->find($id) : 'row-'.$row['i'];
    }

    private function find(string $x): string
    {
        $this->parent[$x] ??= $x;
        while ($this->parent[$x] !== $x) {
            $this->parent[$x] = $this->parent[$this->parent[$x]];
            $x = $this->parent[$x];
        }

        return $x;
    }

    private function union(string $a, string $b): void
    {
        $a = $this->find($a);
        $b = $this->find($b);
        if ($a !== $b) {
            $this->parent[$a] = $b;
        }
    }
}
