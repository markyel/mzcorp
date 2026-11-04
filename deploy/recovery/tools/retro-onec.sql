\pset pager off
select count(*) всего, count(onec_number) с_номером, count(*) filter (where onec_number is null) без_номера,
  count(*) filter (where onec_number is null and status<>'closed_lost') открытых_без_номера,
  count(*) filter (where onec_number is null and exists (select 1 from invoices i where i.request_id=r.id)) без_номера_но_со_счётом
from requests r;

-- Заявка со снимка: что в её письмах, без адресов и текста.
select direction, folder like 'MZ|%' as mz, category, length(coalesce(body_plain,'')) dl,
  body_plain ~* '(М|M)[З3]-?\s*[0-9]{3,}' as mz_v_tekste,
  body_plain ~* '1[СC]' as upom_1c,
  regexp_replace(detected_artifacts->'retro'->>'attachments', '[0-9]', '9', 'g') vlozheniya
from email_messages e join requests r on r.id=e.related_request_id
where r.internal_code='M-2026-21313' and not jsonb_exists(detected_artifacts,'cross_mailbox_copy_of')
order by sent_at;

-- Заявки без номера 1С: где ещё может быть номер.
with n as (select id from requests where onec_number is null),
m as (
  select e.related_request_id rid, e.direction, e.category, coalesce(e.body_plain,'') body, coalesce(e.subject,'') subj,
         coalesce(e.detected_artifacts->'retro'->>'attachments','') att
  from email_messages e join n on n.id=e.related_request_id
  where not jsonb_exists(e.detected_artifacts,'cross_mailbox_copy_of') and coalesce(e.category,'')<>'supplier_reply'
)
select count(distinct rid) заявок,
  count(distinct rid) filter (where subj ~* '(М|M)[З3]-?\s*[0-9]{3,}') мз_в_теме,
  count(distinct rid) filter (where direction='outbound' and body ~* '(М|M)[З3]-?\s*[0-9]{3,}') мз_в_нашем_тексте,
  count(distinct rid) filter (where direction='inbound' and body ~* '(М|M)[З3]-?\s*[0-9]{3,}') мз_в_тексте_клиента,
  count(distinct rid) filter (where att ~* 'предложени') файл_предложение,
  count(distinct rid) filter (where att ~* '(^|[^а-яё])кп([^а-яё]|$)') файл_кп,
  count(distinct rid) filter (where direction='outbound' and body ~* '(номер|№)\s*(заявки|заказа)?\s*(в|из|по)?\s*1[СC]') номер_1с_словами,
  count(distinct rid) filter (where direction='outbound' and body ~* '(коммерческое предложение|кп)\s*№?\s*[0-9]{3,}') кп_номер_в_тексте,
  count(distinct rid) filter (where direction='outbound' and body ~* 'сч[её]т\s*(на оплату)?\s*№?\s*(М|M)?[З3]?-?\s*[0-9]{3,}') счёт_номер_в_тексте
from m;

-- Частые формы имён вложений в наших письмах заявок без номера 1С.
select regexp_replace(left(a,22), '[0-9]+', '#', 'g') форма, count(*) n
from (
  select jsonb_array_elements_text(e.detected_artifacts->'retro'->'attachments') a
  from email_messages e join requests r on r.id=e.related_request_id
  where r.onec_number is null and e.direction='outbound' and coalesce(e.category,'')<>'supplier_reply'
    and not jsonb_exists(e.detected_artifacts,'cross_mailbox_copy_of')
) t
where a ~* '\.(pdf|xlsx?|docx?)$'
group by 1 order by 2 desc limit 15;
