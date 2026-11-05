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
 * Цепочка без номера, начатая до аварии, присоединяется к заявке по номеру 1С:
 * из файла нашего КП или из темы, если её клиент совпадает с клиентом заявки.
 * Новых номеров сборка не выдаёт.
 *
 * Поставщик определяется по маркировке письма (запрос расценки из CRM): такое
 * письмо и его цепочка — переписка с поставщиком, клиентом не становятся. Клиент
 * берётся только из немаркированных писем; если он пропал или сменился, заявка
 * попадает в список на проверку, а клиент не меняется (решение заказчика 05.11).
 * Переписка заявки, в которой нет клиента, тоже считается перепиской с поставщиком.
 */
class RetroAssembler
{
    /** Тема нашего запроса поставщику: токен RFQ из CRM, тег QuoteParts или шаблон темы «Запрос расценки — / Price request —». */
    private const SUPPLIER_MARK_RE = [
        '/\[(?:RFQ|QP)-[A-Z0-9]{4,12}\]/i',
        '/^\s*(?:(?:re|fw|fwd|ответ|отв)\s*:\s*)*(?:запрос расценки|price request)\s*[—–-]/iu',
    ];

    /** Пара «номер заявки / номер 1С» в теме: «[M-2026-21610] / [372377]». */
    private const ONEC_PAIR_RE = '/\[M-(\d{4})-(\d+)\]\s*\/\s*\[(\d{5,7})\]/u';

    /** Ключ заявок без номера: выше любых настоящих номеров. */
    public const UNNUMBERED_KEY = 1000000;

    /** Ключ заявок за дни аварии (MZгод-NNNNN): 2 000 000 + порядковый. */
    public const CRASH_KEY = 2000000;

    /** Номер 1С в теме письма: шесть цифр, допускаются ведущие нули («000369619»). */
    private const ONEC_IN_SUBJECT_RE = '/(?<!\d)0*([1-9]\d{5})(?!\d)/u';

    /** @var array<string, string> */
    private array $parent = [];

    /** Поставщики и цепочки поставщиков последней сборки — для дособирания цепочек без номера. */
    private array $context = [];

    /**
     * @param  array{internal_domains:list<string>, free_mail_domains:list<string>, service_sender_pattern:string,
     *     supplier_min_requests:int, max_legacy_number:int, year:int, idle_close_days:int, mz_folder_owners:array<string,string>,
     *     crash_date?:?string}  $config  crash_date — день аварии: цепочки без номера, начатые с этого дня, не присоединяются
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

        $markedRoots = [];
        foreach ($rows as $row) {
            if ($this->isSupplierMarked($row)) {
                $markedRoots[$this->rootOf($row)] = true;
            }
        }
        $stats['supplier_marked_threads'] = count($markedRoots);

        $groups = $this->groupByNumber($rows);
        $heuristic = $this->likelySupplierAddresses($rows, $groups);
        $marked = $this->markedSupplierAddresses($rows);
        $stats['marked_suppliers'] = count($marked);
        $suppliers = $heuristic + $marked;

        $this->attachByOnecNumber($rows, $groups, $markedRoots, $suppliers, $marked, $stats);
        $this->context = compact('suppliers', 'heuristic', 'marked', 'markedRoots');
        $groups = $this->groupByNumber($rows);
        $stats['unassigned_rows'] = count($rows) - array_sum(array_map('count', $groups));

        $requests = [];
        foreach ($groups as $number => $indexes) {
            $requests[$number] = $this->buildRequest($number, $indexes, $rows, $suppliers, $heuristic, $marked, $markedRoots, $stats);
        }
        ksort($requests);

        return ['requests' => $requests, 'rows' => $rows, 'stats' => $stats];
    }

    /**
     * Заявки из цепочек без номера (решение заказчика 05.11): переписка шла мимо CRM, номер
     * заявки ни в одно письмо не попал и утерян. Берутся цепочки, начатые после запуска
     * системы и до аварии, не переписка с поставщиком, с нашим КП или счётом; $accept может
     * сузить отбор (например, только с оплаченным счётом). Собираются по тем же правилам,
     * что и заявки с номером; код — «R-год-NNNN» по порядку создания начиная с $firstSequence,
     * ключ — 1 000 000 + порядковый (не пересекается с настоящими номерами). Вызывать после
     * assemble() на тех же строках.
     *
     * @param  list<array>  $rows  строки из результата assemble(); письмам цепочек проставляется временный номер
     * @param  callable(list<array>): bool|null  $accept
     * @return array<int, array> ключ → заявка (как в assemble())
     */
    public function assembleUnnumbered(array &$rows, int $firstSequence = 1, ?callable $accept = null): array
    {
        ['suppliers' => $suppliers, 'heuristic' => $heuristic, 'marked' => $marked, 'markedRoots' => $markedRoots] = $this->context;
        $launchTs = ($this->config['launch_date'] ?? null) ? strtotime($this->config['launch_date'].' 00:00:00 Europe/Moscow') : 0;
        $crashTs = ($this->config['crash_date'] ?? null) ? strtotime($this->config['crash_date'].' 00:00:00 Europe/Moscow') : PHP_INT_MAX;

        $threads = [];
        foreach ($rows as $i => $row) {
            if ($row['number'] === null) {
                $threads[$this->rootOf($row)][] = $i;
            }
        }
        $chains = [];
        foreach ($threads as $root => $indexes) {
            $first = min(array_map(fn ($i) => $rows[$i]['ts'] ?? 0, $indexes));
            if (isset($markedRoots[$root]) || $first < $launchTs || $first >= $crashTs) {
                continue;
            }
            // Наш КП или счёт, ушедший наружу: пересылка документа коллеге — не сделка.
            $hasDocument = false;
            foreach ($indexes as $i) {
                if ($rows[$i]['outbound'] && $this->documents($rows[$i]) !== [] && $this->externalRecipients($rows[$i]) !== []) {
                    $hasDocument = true;
                    break;
                }
            }
            if (! $hasDocument || ($accept !== null && ! $accept(array_map(fn ($i) => $rows[$i], $indexes)))) {
                continue;
            }
            usort($indexes, fn ($a, $b) => ($rows[$a]['ts'] ?? 0) <=> ($rows[$b]['ts'] ?? 0));
            $chains[] = ['first' => $first, 'indexes' => $indexes];
        }
        usort($chains, fn ($a, $b) => $a['first'] <=> $b['first']);

        $stats = ['no_client' => 0];
        $requests = [];
        foreach ($chains as $k => $chain) {
            $sequence = $firstSequence + $k;
            $number = self::UNNUMBERED_KEY + $sequence;
            foreach ($chain['indexes'] as $i) {
                $rows[$i]['number'] = $number;
            }
            $request = $this->buildRequest($number, $chain['indexes'], $rows, $suppliers, $heuristic, $marked, $markedRoots, $stats);
            $request['code'] = sprintf('R-%d-%04d', $this->config['year'], $sequence);
            $requests[$number] = $request;
        }

        return $requests;
    }

    /**
     * Заявки за дни аварии (решение заказчика 05.11): CRM не работала, обращения на общий ящик
     * пересылали менеджерам, дальше менеджер вёл переписку со своего ящика; часть клиентов писала
     * менеджеру лично. Берутся цепочки без номера, начатые с crash_date, не переписка с поставщиком:
     * письмо клиента в общем ящике связывается с его пересылкой менеджеру (тема без Re/Fw, пересылка
     * не раньше письма и не позже трёх суток), цепочки одного клиента у одного менеджера — одна заявка.
     * Ответственный — менеджер, писавший клиенту, иначе тот, кому переслали, иначе владелец ящика,
     * куда клиент написал. Код — «MZгод-NNNNN» (решение заказчика), ключ — 2 000 000 + порядковый.
     * Вызывать после assemble() на тех же строках.
     *
     * @param  list<string>  $sharedBoxes  общие ящики (info@): из них ответственный не берётся
     * @param  callable(list<array>): bool|null  $unassigned  цепочки без менеджера (или без клиента), которые всё же заявки —
     *                                                    по списку, выбранному вручную: заводятся без ответственного
     * @return array{requests:array<int, array>, skipped:list<array>, stats:array<string, int>}
     */
    public function assembleCrash(array &$rows, array $sharedBoxes, int $firstSequence = 1, ?callable $accept = null, ?callable $unassigned = null): array
    {
        ['suppliers' => $suppliers, 'heuristic' => $heuristic, 'marked' => $marked, 'markedRoots' => $markedRoots] = $this->context;
        $crashTs = strtotime(($this->config['crash_date'] ?? 'now').' 00:00:00 Europe/Moscow');
        $shared = array_flip(array_map('mb_strtolower', $sharedBoxes));
        $handlers = array_flip($this->handlerEmails);
        $stats = ['forwards' => 0, 'forwards_linked' => 0, 'no_client' => 0];

        // Цепочка, начатая до аварии, — продолжение старой переписки, а не новое обращение;
        // переписка с поставщиком по маркировке — тоже не заявка. Считается до объединения цепочек.
        $firstOfRoot = [];
        foreach ($rows as $row) {
            if ($row['number'] === null) {
                $root = $this->rootOf($row);
                $firstOfRoot[$root] = min($firstOfRoot[$root] ?? PHP_INT_MAX, $row['ts'] ?? 0);
            }
        }
        $candidates = [];
        foreach ($rows as $i => $row) {
            $root = $this->rootOf($row);
            if ($row['number'] === null && ($row['ts'] ?? 0) >= $crashTs && ! isset($markedRoots[$root]) && ($firstOfRoot[$root] ?? 0) >= $crashTs) {
                $candidates[] = $i;
            }
        }

        // Пересылка из общего ящика менеджеру ↔ письмо клиента в общем ящике.
        $clientLetters = [];
        foreach ($candidates as $i) {
            $row = $rows[$i];
            if (isset($shared[$row['box']]) && ! $row['outbound'] && $this->isClientAddress($row['from_email'], $suppliers)) {
                $clientLetters[self::bareSubject($row['subject'])][] = $i;
            }
        }
        $forwardedTo = [];   // корень цепочки → менеджер, которому переслали
        foreach ($candidates as $i) {
            $row = $rows[$i];
            $manager = null;
            if (isset($shared[$row['box']]) && $row['outbound']) {
                $manager = array_values(array_filter($row['recipients'], fn ($a) => isset($handlers[$a])))[0] ?? null;
            } elseif (isset($handlers[$row['box']]) && ! $row['outbound'] && isset($shared[$row['from_email']])) {
                $manager = $row['box'];
            }
            if ($manager === null) {
                continue;
            }
            $stats['forwards']++;
            $original = null;
            foreach ($clientLetters[self::bareSubject($row['subject'])] ?? [] as $j) {
                if ($rows[$j]['ts'] <= $row['ts'] + 600 && $row['ts'] - $rows[$j]['ts'] < 3 * 86400) {
                    $original = $j;
                }
            }
            if ($original === null) {
                continue;
            }
            $stats['forwards_linked']++;
            $this->union($this->rootKey($rows[$original]), $this->rootKey($row));
            $forwardedTo[$this->rootOf($rows[$original])] = $manager;
        }

        // Клиент и менеджер каждой цепочки; цепочки одного клиента у одного менеджера — одна заявка.
        $threads = [];
        foreach ($candidates as $i) {
            $threads[$this->rootOf($rows[$i])][] = $i;
        }
        $forwardedTo = array_combine(array_map(fn ($r) => $this->find($r), array_keys($forwardedTo)), array_values($forwardedTo));
        $byClientManager = [];
        foreach ($threads as $root => $indexes) {
            $group = array_map(fn ($i) => $rows[$i], $indexes);
            [$client] = $this->client($group, $suppliers, $marked);
            $manager = $this->crashManager($group, $handlers) ?? ($forwardedTo[$root] ?? null) ?? $this->crashMailboxOwner($group, $handlers);
            if ($client !== null && $manager !== null) {
                $byClientManager[$this->addressKey($client).'|'.$manager][] = $root;
            }
        }
        foreach ($byClientManager as $roots) {
            foreach (array_slice($roots, 1) as $root) {
                $this->union($roots[0], $root);
            }
        }

        $chains = [];
        foreach ($candidates as $i) {
            $chains[$this->rootOf($rows[$i])][] = $i;
        }
        $forwardedByChain = [];
        foreach ($forwardedTo as $root => $manager) {
            $forwardedByChain[$this->find($root)] ??= $manager;
        }

        $accepted = [];
        $skipped = [];
        foreach ($chains as $root => $indexes) {
            usort($indexes, fn ($a, $b) => ($rows[$a]['ts'] ?? 0) <=> ($rows[$b]['ts'] ?? 0));
            $group = array_map(fn ($i) => $rows[$i], $indexes);
            [$client] = $this->client($group, $suppliers, $marked);
            $manager = $this->crashManager($group, $handlers) ?? ($forwardedByChain[$root] ?? null) ?? $this->crashMailboxOwner($group, $handlers);
            $chosen = $unassigned !== null && $unassigned($group);
            $reason = match (true) {
                $accept !== null && ! $accept($group) => 'уже в базе',
                $chosen => null,
                $client === null || ! $this->isClientAddress($client, $suppliers) => 'нет клиента',
                $manager === null => 'нет менеджера',
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = ['reason' => $reason, 'first' => $group[0], 'count' => count($group), 'client' => $client];
                $stats['skipped: '.$reason] = ($stats['skipped: '.$reason] ?? 0) + 1;

                continue;
            }
            $accepted[] = ['first' => $group[0]['ts'] ?? 0, 'indexes' => $indexes, 'manager' => $manager, 'forwarded' => isset($forwardedByChain[$root])];
        }
        usort($accepted, fn ($a, $b) => $a['first'] <=> $b['first']);

        $requests = [];
        foreach ($accepted as $k => $chain) {
            $sequence = $firstSequence + $k;
            $number = self::CRASH_KEY + $sequence;
            foreach ($chain['indexes'] as $i) {
                $rows[$i]['number'] = $number;
            }
            $request = $this->buildRequest($number, $chain['indexes'], $rows, $suppliers, $heuristic, $marked, $markedRoots, $stats);
            $request['code'] = sprintf('MZ%d-%05d', $this->config['year'], $sequence);
            if ($request['assigned'] === null && $chain['manager'] !== null) {
                $request['assigned'] = $chain['manager'];
                $request['first_assigned'] ??= $chain['manager'];
                if ($request['status'] === RequestStatus::New) {
                    $request['status'] = RequestStatus::Assigned;
                }
            }
            $request['crash_source'] = $chain['manager'] === null ? 'chosen' : ($chain['forwarded'] ? 'forwarded' : 'direct');
            $requests[$number] = $request;
        }

        return ['requests' => $requests, 'skipped' => $skipped, 'stats' => $stats];
    }

    /** Менеджер, писавший клиенту из своего ящика (последний). */
    private function crashManager(array $group, array $handlers): ?string
    {
        $manager = null;
        foreach ($group as $row) {
            if ($row['outbound'] && isset($handlers[$row['box']]) && $this->externalRecipients($row) !== []) {
                $manager = $row['box'];
            }
        }

        return $manager;
    }

    /**
     * Письмо разложено в папку менеджера общего ящика (MZ|…) — её владелец; клиент написал
     * менеджеру лично — владелец ящика, куда пришло первое письмо.
     */
    private function crashMailboxOwner(array $group, array $handlers): ?string
    {
        foreach ($group as $row) {
            $owner = mb_strtolower((string) ($this->config['mz_folder_owners'][$row['folder']] ?? ''));
            if ($owner !== '' && isset($handlers[$owner])) {
                return $owner;
            }
        }
        foreach ($group as $row) {
            if (! $row['outbound'] && isset($handlers[$row['box']])) {
                return $row['box'];
            }
        }

        return null;
    }

    /** Тема без Re:/Fw:/Fwd:/Отв: — для связи пересылки с исходным письмом. */
    public static function bareSubject(string $subject): string
    {
        return mb_strtolower(trim(preg_replace('/^\s*((re|fw|fwd|aw|wg|ответ|отв|пересл)\s*(\[\d+\])?\s*:\s*)+/iu', '', $subject)));
    }

    /** Узел цепочки письма для union (как rootOf, но без поиска корня). */
    private function rootKey(array $row): string
    {
        return $row['mid'] ?? $row['in_reply_to'] ?? ($row['references'][0] ?? 'row-'.$row['i']);
    }

    /** @return array<int, list<int>> номер → позиции писем */
    private function groupByNumber(array $rows): array
    {
        $groups = [];
        foreach ($rows as $i => $row) {
            if ($row['number'] !== null) {
                $groups[$row['number']][] = $i;
            }
        }

        return $groups;
    }

    private function isSupplierMarked(array $row): bool
    {
        return self::isSupplierSubject($row['subject']);
    }

    /** Тема нашего запроса поставщику или ответа на него (маркировка CRM). */
    public static function isSupplierSubject(string $subject): bool
    {
        foreach (self::SUPPLIER_MARK_RE as $re) {
            if (preg_match($re, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Адреса из наших маркированных запросов и ответов на них, которые ни разу не получали
     * наш КП или счёт: поставщики и в немаркированных письмах. Получавшие документ бывают
     * и клиентами (дилеры), их определяет только маркировка письма.
     */
    private function markedSupplierAddresses(array $rows): array
    {
        $marked = [];
        $gotDocument = [];
        foreach ($rows as $row) {
            if ($row['outbound'] && $this->documents($row) !== []) {
                foreach ($this->externalRecipients($row) as $address) {
                    $gotDocument[$this->addressKey($address)] = true;
                }
            }
            if (! $this->isSupplierMarked($row)) {
                continue;
            }
            $addresses = $row['outbound'] ? $this->externalRecipients($row) : ($this->isExternal($row['from_email']) ? [$row['from_email']] : []);
            foreach ($addresses as $address) {
                $marked[$this->addressKey($address)] = true;
            }
        }

        return array_diff_key($marked, $gotDocument);
    }

    /**
     * Цепочки без номера, начатые до аварии, получают настоящий номер по номеру 1С: из файла
     * нашего КП — всегда, из темы — если клиент цепочки совпадает с клиентом заявки (шестизначные
     * числа в темах часто совпадают случайно). Номер 1С ведёт к заявке через пару «[M-…] / [1С]»
     * в теме или через КП с тем же номером в цепочке с номером. Неоднозначные не трогаем.
     */
    private function attachByOnecNumber(array &$rows, array $groups, array $markedRoots, array $suppliers, array $marked, array &$stats): void
    {
        $stats['attached_by_quote_file'] = 0;
        $stats['attached_by_subject'] = 0;
        $stats['attach_ambiguous'] = 0;

        $bridge = [];
        foreach ($rows as $row) {
            if (preg_match_all(self::ONEC_PAIR_RE, $row['subject'], $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $p) {
                    if ((int) $p[1] === $this->config['year'] && isset($groups[(int) $p[2]])) {
                        $bridge[(string) (int) $p[3]][(int) $p[2]] = true;
                    }
                }
            }
            if ($row['number'] !== null && $row['outbound']) {
                foreach ($this->documents($row) as $document) {
                    if ($document['type'] === 'quote') {
                        $bridge[(string) (int) $document['number']][$row['number']] = true;
                    }
                }
            }
        }

        $clients = [];
        $threads = [];
        $crashTs = ($this->config['crash_date'] ?? null) ? strtotime($this->config['crash_date'].' 00:00:00 Europe/Moscow') : PHP_INT_MAX;
        foreach ($rows as $i => $row) {
            if ($row['number'] === null) {
                $threads[$this->rootOf($row)][] = $i;
            }
        }
        foreach ($threads as $root => $indexes) {
            if (isset($markedRoots[$root]) || min(array_map(fn ($i) => $rows[$i]['ts'] ?? 0, $indexes)) >= $crashTs) {
                continue;
            }
            $byFile = [];
            $bySubject = [];
            $externals = [];
            foreach ($indexes as $i) {
                $row = $rows[$i];
                foreach (array_merge($this->externalRecipients($row), $this->isExternal($row['from_email']) ? [$row['from_email']] : []) as $address) {
                    $externals[$this->addressKey($address)] = true;
                }
                if ($row['outbound']) {
                    foreach ($this->documents($row) as $document) {
                        if ($document['type'] === 'quote') {
                            $byFile += $bridge[(string) (int) $document['number']] ?? [];
                        }
                    }
                }
                if (preg_match_all(self::ONEC_IN_SUBJECT_RE, $row['subject'], $found)) {
                    foreach ($found[1] as $onec) {
                        $bySubject += $bridge[(string) (int) $onec] ?? [];
                    }
                }
            }
            foreach (array_keys($bySubject) as $number) {
                $clients[$number] ??= $this->client($this->plainRows($groups[$number], $rows, $markedRoots), $suppliers, $marked)[0];
                if ($clients[$number] === null || ! isset($externals[$this->addressKey($clients[$number])])) {
                    unset($bySubject[$number]);
                }
            }
            $candidates = $byFile + $bySubject;
            if (count($candidates) !== 1) {
                $stats['attach_ambiguous'] += count($candidates) > 1 ? 1 : 0;

                continue;
            }
            $number = array_key_first($candidates);
            $how = isset($byFile[$number]) ? 'quote_file' : 'subject';
            $stats[$how === 'quote_file' ? 'attached_by_quote_file' : 'attached_by_subject']++;
            foreach ($indexes as $i) {
                $rows[$i]['number'] = $number;
                $rows[$i]['attached'] = $how;
            }
        }
    }

    /** Письма заявки без маркированных цепочек поставщиков. */
    private function plainRows(array $indexes, array $rows, array $markedRoots): array
    {
        $out = [];
        foreach ($indexes as $i) {
            if (! isset($markedRoots[$this->rootOf($rows[$i])])) {
                $out[] = $rows[$i];
            }
        }

        return $out;
    }

    /** @return list<array{type:string, number:string, date:?string}> наши КП и счета во вложениях письма */
    private function documents(array $row): array
    {
        $out = [];
        foreach ($row['names'] as $name) {
            if (($document = RetroMailParser::documentFromFilename($name)) !== null) {
                $out[] = $document;
            }
        }

        return $out;
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

    private function buildRequest(int $number, array $indexes, array $rows, array $suppliers, array $heuristic, array $marked, array $markedRoots, array &$stats): array
    {
        $group = array_map(fn ($i) => $rows[$i], $indexes);
        // Клиент — только из писем без маркировки поставщика. Если по прежнему правилу он был,
        // а теперь пропал или стал другим — заявка на проверку, клиента сами не меняем.
        [$clientEmail, $clientName, $clientFrom] = $this->client($this->plainRows($indexes, $rows, $markedRoots), $suppliers, $marked);
        $previous = $this->client($group, $heuristic);
        $review = null;
        if ($clientEmail === null && $previous[0] !== null) {
            $review = ['reason' => 'no_client', 'previous' => $previous[0]];
        } elseif ($clientEmail !== null && $previous[0] !== null && $this->addressKey($clientEmail) !== $this->addressKey($previous[0])) {
            $review = ['reason' => 'client_changed', 'previous' => $previous[0], 'found' => $clientEmail];
            [$clientEmail, $clientName, $clientFrom] = $previous;
        }
        if ($review !== null) {
            $stats['review_'.$review['reason']] = ($stats['review_'.$review['reason']] ?? 0) + 1;
        }
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
            $supplierChain[$root] = isset($markedRoots[$root])
                || ($clientEmail !== null && ! $this->anyMatchesClient(array_keys($addresses), $clientEmail));
        }
        foreach ($group as $row) {
            $supplierChain[$this->rootOf($row)] ??= isset($markedRoots[$this->rootOf($row)]);
        }

        $messages = [];
        $seen = [];
        $firstInbound = null;
        $lastAuthor = null;
        $firstAuthor = null;
        // Автор наших запросов поставщикам — ответственный, если писем клиенту в почте нет.
        $supplierFirstAuthor = null;
        $supplierLastAuthor = null;
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
                if ($row['outbound'] && in_array($row['box'], $this->handlerEmails, true)) {
                    $supplierFirstAuthor ??= $row['box'];
                    $supplierLastAuthor = $row['box'];
                }

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
        $assigned = $lastAuthor ?? $mzOwner ?? $firstAuthor ?? $supplierLastAuthor;
        $firstAssigned = $mzOwner ?? $firstAuthor ?? $supplierFirstAuthor ?? $assigned;

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
            'review' => $review,
        ];
    }

    /**
     * @param  array<string, true>  $never  адреса, которые клиентом не бывают даже в крайнем случае (поставщики по маркировке)
     * @return array{0:?string, 1:?string, 2:bool} Адрес, имя, найден ли клиент по входящему письму.
     */
    private function client(array $group, array $suppliers, array $never = []): array
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
        // Поставщик по маркировке клиентом не становится и здесь.
        foreach ($group as $row) {
            if (! $row['outbound'] && $this->isExternal($row['from_email']) && ! isset($never[$this->addressKey($row['from_email'])])) {
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

        return $this->find($id ?? 'row-'.$row['i']);
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
