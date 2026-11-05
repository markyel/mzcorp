#!/usr/bin/env python3
"""Итоговый анализ замера: цепочки, номера, документы. Только агрегаты, без адресов и текста писем."""
import collections
import email.utils
import glob
import json
import os
import re
import urllib.parse

ROOT = '/var/tmp/retro-probe-personal'
INFO = '/var/tmp/retro-probe'
INTERNAL = re.compile(r'@(myzip\.ru|mylift\.ru|mzcorp\.ru)\b', re.I)
MAXCODE = 25000
START = '2026-05-25'

rows = []
for box in sorted(os.listdir(ROOT)):
    d = os.path.join(ROOT, box)
    if os.path.isdir(d):
        for f in glob.glob(d + '/*.jsonl'):
            for line in open(f, encoding='utf-8'):
                try:
                    r = json.loads(line)
                    r['box'] = box
                    rows.append(r)
                except ValueError:
                    pass
for f in glob.glob(INFO + '/*.jsonl'):
    for line in open(f, encoding='utf-8'):
        try:
            r = json.loads(line)
            r['box'] = 'info@myzip.ru'
            rows.append(r)
        except ValueError:
            pass


def day(r):
    try:
        return email.utils.parsedate_to_datetime(r.get('date') or '').strftime('%Y-%m-%d')
    except Exception:
        return ''


def good(cs):
    out = set()
    for c in cs or []:
        n = int(c.rsplit('-', 1)[1])
        if c.startswith('M-2026-') and 1 <= n <= MAXCODE:
            out.add(n)
    return out


def addrs(r):
    return [a.lower() for a in re.findall(r'[\w.+-]+@[\w.-]+', (r.get('to') or '') + ' ' + (r.get('cc') or ''))]


def external(r):
    return [a for a in addrs(r) if not INTERNAL.search(a)]


def is_sent(r):
    return r['folder'] == 'Sent'


noise = set()
for r in rows:
    r['d'] = day(r)
    r['c'] = good(r.get('codes')) | good(r.get('foot'))
    for c in list(r.get('codes') or []) + list(r.get('foot') or []):
        if int(c.rsplit('-', 1)[1]) > MAXCODE:
            noise.add(c)

# Союз цепочек по Message-ID, In-Reply-To и References.
parent = {}


def find(x):
    parent.setdefault(x, x)
    while parent[x] != x:
        parent[x] = parent[parent[x]]
        x = parent[x]
    return x


def union(a, b):
    a, b = find(a), find(b)
    if a != b:
        parent[a] = b


for r in rows:
    ids = re.findall(r'<[^>\s]+>', (r.get('mid') or '') + ' ' + (r.get('irt') or '') + ' ' + (r.get('refs') or ''))
    r['ids'] = ids
    for i in ids[1:]:
        union(ids[0], i)

comp_codes = collections.defaultdict(set)
for r in rows:
    if r['ids']:
        comp_codes[find(r['ids'][0])] |= r['c']


def comp(r):
    return comp_codes.get(find(r['ids'][0]), set()) if r['ids'] else set()


print('=== номера')
allc = set()
for r in rows:
    allc |= r['c']
print('разных номеров всего:', len(allc), ' макс:', max(allc), ' мин:', min(allc), ' отброшено как шум:', len(noise))
for a in range(0, 24000, 2000):
    n = sum(1 for x in allc if a <= x < a + 2000)
    print(f'{a:>6}-{a + 1999:<6} {n:>5} ({100 * n // 2000}%)')

sizes = collections.Counter()
for c in comp_codes.values():
    sizes['0' if not c else ('1' if len(c) == 1 else ('2-3' if len(c) <= 3 else '4+'))] += 1
print('цепочек по числу номеров:', dict(sizes))

print('\n=== что известно о каждом номере')
first = {}
owners = collections.defaultdict(collections.Counter)
ext = set()
withdoc = set()
for r in rows:
    for n in r['c']:
        if r['d'] and (n not in first or r['d'] < first[n]):
            first[n] = r['d']
        if is_sent(r):
            owners[n][r['box']] += 1
            if external(r):
                ext.add(n)
print('с внешним получателем:', len(ext), ' из', len(allc))
one = sum(1 for n in allc if len(owners[n]) == 1)
print('один ящик-автор:', one, ' несколько:', sum(1 for n in allc if len(owners[n]) > 1), ' нет автора:', sum(1 for n in allc if not owners[n]))
inbound = set()
for r in rows:
    if not is_sent(r) and r['ids']:
        for n in comp(r):
            inbound.add(n)
print('в цепочке есть входящее письмо:', len(inbound))
mon = collections.Counter(first[n][:7] for n in first)
print('по месяцу первого письма:', dict(sorted(mon.items())))

print('\n=== 25-27 мая: отправленные наружу')
t = collections.Counter()
for r in rows:
    if is_sent(r) and '2026-05-25' <= r['d'] <= '2026-05-27' and external(r) and r['box'] != 'info@myzip.ru':
        c = comp(r)
        t['всего'] += 1
        t['нет номера в цепочке' if not c else ('один номер' if len(c) == 1 else 'несколько номеров')] += 1
print(dict(t))

print('\n=== папки MZ| у info@: письма с 25 мая')
for f in sorted({r['folder'] for r in rows if r['box'] == 'info@myzip.ru' and r['folder'].startswith('MZ|')}):
    t = collections.Counter()
    for r in rows:
        if r['box'] == 'info@myzip.ru' and r['folder'] == f and r['d'] >= START:
            c = comp(r)
            t['всего'] += 1
            t['нет номера' if not c else ('один номер' if len(c) == 1 else 'несколько')] += 1
    print(f, dict(t))

print('\n=== отправленные наружу без номера в цепочке, с 25 мая')
numbered_rcpt = set()
for r in rows:
    if is_sent(r) and comp(r):
        numbered_rcpt.update(external(r))
for box in sorted({r['box'] for r in rows}):
    t = collections.Counter()
    for r in rows:
        if r['box'] != box or not is_sent(r) or r['d'] < START or not external(r):
            continue
        t['наружу'] += 1
        if comp(r):
            t['с номером'] += 1
            continue
        t['без номера'] += 1
        t['ответ в цепочке' if (r.get('irt') or r.get('refs')) else 'новое письмо'] += 1
        t['получатель встречался в письмах с номером' if any(a in numbered_rcpt for a in external(r)) else 'получатель без писем с номером'] += 1
        if r.get('crm'):
            t['из CRM'] += 1
    print(box, dict(t))

print('\n=== документы во вложениях отправленных, с 25 мая')


def fname(n):
    n = str(n)
    if "''" in n:
        n = urllib.parse.unquote(n.split("''", 1)[1])
    return n


KP = re.compile(r'предложени[ея]\s*№?\s*[МM][З3]-?\s*(\d+)', re.I)
INV = re.compile(r'сч[её]т(?:\s+на\s+оплату)?\s*№?\s*[МM][З3]-?\s*(\d+)', re.I)
kp = collections.defaultdict(set)
inv = collections.defaultdict(set)
shape = collections.Counter()
for r in rows:
    if not r.get('names') or r['d'] < START:
        continue
    for raw in r['names']:
        n = fname(raw)
        m = KP.search(n)
        if m:
            kp[m.group(1)].add(find(r['ids'][0]) if r['ids'] else None)
        m2 = INV.search(n)
        if m2:
            inv[m2.group(1)].add(find(r['ids'][0]) if r['ids'] else None)
        if not m and not m2 and n.lower().endswith(('.pdf', '.xls', '.xlsx', '.doc', '.docx')):
            shape[re.sub(r'\d+', '#', n)[:18]] += 1
for label, docs in (('КП', kp), ('счета', inv)):
    linked = sum(1 for k, v in docs.items() if any(x and comp_codes.get(x) for x in v))
    print(label, 'разных номеров документа:', len(docs), ' из них в цепочке с номером заявки:', linked)
print('прочие частые имена:', [k for k, v in shape.most_common(20) if v >= 50])

print('\n=== \\Seen до и после')
bad = 0
tot = 0
for box in sorted(os.listdir(ROOT)):
    d = os.path.join(ROOT, box)
    if not os.path.isdir(d):
        continue
    if not os.path.exists(d + '/status-after.json'):
        print(box, 'нет итогового файла')
        continue
    b = json.load(open(d + '/status-before.json', encoding='utf-8'))
    a = json.load(open(d + '/status-after.json', encoding='utf-8'))
    for f in b:
        tot += 1
        du = a.get(f, {}).get('unseen', 0) - b[f]['unseen']
        dm = a.get(f, {}).get('messages', 0) - b[f]['messages']
        if du < 0:
            bad += 1
        if du or dm:
            print(box, f[:24], 'писем', b[f]['messages'], '->', a.get(f, {}).get('messages'), ' непрочитанных', b[f]['unseen'], '->', a.get(f, {}).get('unseen'))
print('папок:', tot, ' где непрочитанных стало меньше:', bad)
