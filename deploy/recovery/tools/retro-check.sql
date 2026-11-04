\pset format aligned
\pset pager off
select 'заявок' k, count(*) v from requests
union all select 'писем', count(*) from email_messages where related_request_id is not null
union all select 'копий', count(*) from email_messages where detected_artifacts ? 'cross_mailbox_copy_of'
union all select 'заявка без исходного письма', count(*) from requests where email_message_id is null
union all select 'заявка без клиента', count(*) from requests where client_email = ''
union all select 'заявка без ответственного', count(*) from requests where assigned_user_id is null
union all select 'с номером 1С', count(*) from requests where onec_number is not null
union all select 'КП', count(*) from outbound_quotes
union all select 'счетов', count(*) from invoices
union all select 'заявок со счётом', count(distinct request_id) from invoices
union all select 'назначений', count(*) from request_assignments
union all select 'смен статуса', count(*) from request_state_changes
union all select 'прочитано', count(*) from email_message_user_states
union all select 'задач в очереди', count(*) from jobs
union all select 'неудачных задач', count(*) from failed_jobs
union all select 'черновиков', count(*) from email_messages where is_draft
union all select 'писем без категоризации', count(*) from email_messages where direction='inbound' and categorized_at is null
union all select 'папок личных ящиков', count(*) from mailbox_folders
union all select 'счётчик новых номеров', (select last_value from request_code_sequences limit 1);
select status, count(*) from requests group by 1 order by 2 desc;
select to_char(created_at,'YYYY-MM') mon, count(*) заявок, sum((status='closed_lost')::int) закрыто from requests group by 1 order by 1;
select i.status, count(*) from invoices i group by 1;
select count(*) "заявки: письмо из другой заявки" from requests r join email_messages e on e.id=r.email_message_id where e.related_request_id is distinct from r.id;
select internal_code, status, peak_status, length(subject) dl_temy, (select count(*) from email_messages e where e.related_request_id=r.id) писем, (select count(*) from outbound_quotes q where q.request_id=r.id) кп, (select count(*) from invoices q where q.request_id=r.id) счетов, to_char(created_at,'DD.MM') создана, to_char(last_activity_at,'DD.MM') актив from requests r where status not in ('closed_lost') order by last_activity_at desc limit 6;
select max(cnt) "макс писем в заявке", percentile_cont(0.5) within group (order by cnt) "медиана" from (select related_request_id, count(*) cnt from email_messages where related_request_id is not null group by 1) t;
