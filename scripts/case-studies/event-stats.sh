#!/usr/bin/env bash
# Statistiques agrégées (lecture seule) des événements suivis par les cas clients.
# Usage : scripts/case-studies/event-stats.sh 4 6
# Ne renvoie aucune donnée personnelle ni aucun montant.
set -euo pipefail

HOST="${PICHA_PROD_HOST:-cathy-vps}"
CONTAINER="${PICHA_PROD_DB_CONTAINER:-picha-ticket-prod-postgres-1}"
IDS=$(printf '%s,' "$@" | sed 's/,$//')
[[ "$IDS" =~ ^[0-9]+(,[0-9]+)*$ ]] || { echo "Usage : $0 <event_id> [...]" >&2; exit 1; }

read -r -d '' SQL <<SQL || true
select json_agg(x order by x.id) from (
  select e.id, e.title, e.status, e.category, e.timezone,
    to_char(e.start_date at time zone 'UTC' at time zone e.timezone, 'YYYY-MM-DD"T"HH24:MI') as start_local,
    to_char(e.end_date at time zone 'UTC' at time zone e.timezone, 'YYYY-MM-DD"T"HH24:MI') as end_local,
    o.name as organizer,
    es.location_details as location,
    (select min(created_at) from orders where event_id = e.id and status = 'COMPLETED') as first_sale_utc,
    (select count(*) from attendees where event_id = e.id and status = 'ACTIVE' and deleted_at is null) as tickets_active,
    (select count(*) from orders where event_id = e.id and status = 'COMPLETED' and deleted_at is null) as orders_completed,
    (select json_agg(c) from (
        select case when od.payment_provider is not null then od.payment_provider when od.total_gross = 0 then 'GRATUIT' else 'HORS_LIGNE' end as channel, p.title as product, count(*) as tickets
        from attendees a join orders od on od.id = a.order_id join products p on p.id = a.product_id
        where a.event_id = e.id and a.status = 'ACTIVE' and a.deleted_at is null
        group by 1, 2 order by 1, 2) c) as tickets_by_channel_product,
    (select json_agg(pr) from (
        select p.title as product, pp.label, pp.price, pp.initial_quantity_available as capacity, pp.quantity_sold
        from products p join product_prices pp on pp.product_id = p.id
        where p.event_id = e.id and p.deleted_at is null and pp.deleted_at is null order by p.id, pp.id) pr) as prices,
    (select json_agg(d) from (
        select (a.created_at at time zone 'UTC' at time zone e.timezone)::date as day,
          case when od.payment_provider is not null then od.payment_provider when od.total_gross = 0 then 'GRATUIT' else 'HORS_LIGNE' end as channel,
          count(*) as tickets
        from attendees a join orders od on od.id = a.order_id
        where a.event_id = e.id and a.status = 'ACTIVE' and a.deleted_at is null
        group by 1, 2 order by 1, 2) d) as tickets_by_day,
    (select count(distinct attendee_id) from attendee_check_ins where event_id = e.id and deleted_at is null) as checked_in,
    (select json_agg(h) from (
        select to_char(ci.created_at at time zone 'UTC' at time zone e.timezone, 'YYYY-MM-DD HH24:00') as hour, count(*) as scans
        from attendee_check_ins ci where ci.event_id = e.id and ci.deleted_at is null
        group by 1 order by 1) h) as check_ins_by_hour
  from events e
  join organizers o on o.id = e.organizer_id
  left join event_settings es on es.event_id = e.id
  where e.id in ($IDS) and e.deleted_at is null
) x;
SQL

ssh "$HOST" "docker exec -i $CONTAINER sh -c 'psql -q -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -At -v ON_ERROR_STOP=1'" <<<"SET default_transaction_read_only = on; $SQL"
