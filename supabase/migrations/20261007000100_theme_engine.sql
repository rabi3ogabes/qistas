-- ============================================================================
-- Qistas theme engine — country / event / tenant-accent theming, admin-controlled.
--
-- Run order: after the Phase-1 foundation migration (tenants, users). The migration is self-contained and
-- idempotent; the foreign key to `tenants` is added only if that table already exists.
--
-- Resolution rules live in brand/shared/qistas-theme.js (mirrored by the Laravel resolver and the Flutter
-- client). The database enforces SHAPE and SAFETY (valid hex colours, valid scopes/recurrences, exactly one
-- published base theme, versioning, RLS). WCAG contrast is enforced by the Laravel publish endpoint and again
-- by every client at resolve time.
--
-- STATUS: written for Supabase / PostgreSQL 15+. Not executed against a live database in this repository yet.
-- ============================================================================
begin;

-- ---------------------------------------------------------------- helpers (JWT claims, as set by PostgREST / Supabase)
create or replace function public.is_platform_admin() returns boolean
language sql stable
as $$
  select coalesce(
    nullif(current_setting('request.jwt.claims', true), '')::jsonb -> 'app_metadata' ->> 'platform_role', ''
  ) in ('super_admin', 'admin_staff');
$$;

create or replace function public.current_tenant_id() returns uuid
language sql stable
as $$
  select nullif(nullif(current_setting('request.jwt.claims', true), '')::jsonb -> 'app_metadata' ->> 'tenant_id', '')::uuid;
$$;

-- ---------------------------------------------------------------- types
do $$ begin create type public.theme_kind   as enum ('base', 'country', 'event');          exception when duplicate_object then null; end $$;
do $$ begin create type public.theme_status as enum ('draft', 'published', 'archived');    exception when duplicate_object then null; end $$;

-- ---------------------------------------------------------------- validation functions (immutable, used in CHECKs)
create or replace function public.q_token_names() returns text[]
language sql immutable
as $$
  select array['primary','onPrimary','action','onAction','accent','onAccent','accentText','info','onInfo',
               'bg','surface','surfaceAlt','ink','inkMuted','line','positive','warning','danger',
               'tintSky','tintBlush','tintSand','tintMint','heroFrom','heroTo','logoInk','logoAccent'];
$$;

create or replace function public.q_valid_mode_tokens(m jsonb) returns boolean
language sql immutable
as $$
  select coalesce(jsonb_typeof(m) = 'object', false)
     and not exists (
       select 1 from jsonb_each(m) e
       where e.key <> all (public.q_token_names())
          or jsonb_typeof(e.value) <> 'string'
          or (e.value #>> '{}') !~ '^#[0-9A-Fa-f]{6}$'
     );
$$;

create or replace function public.q_valid_tokens(t jsonb) returns boolean
language sql immutable
as $$
  select coalesce(jsonb_typeof(t) = 'object', false)
     and public.q_valid_mode_tokens(t -> 'light')
     and public.q_valid_mode_tokens(t -> 'dark');
$$;

create or replace function public.q_valid_countries(c text[]) returns boolean
language sql immutable
as $$
  select coalesce(cardinality(c) > 0, false)
     and not exists (select 1 from unnest(c) x where x is null or x !~ '^(\*|[A-Z]{2})$');
$$;

create or replace function public.q_valid_wildcard_list(c text[]) returns boolean
language sql immutable
as $$
  select coalesce(cardinality(c) > 0, false)
     and not exists (select 1 from unnest(c) x where x is null or x = '');
$$;

create or replace function public.q_valid_recurrence(r jsonb) returns boolean
language sql immutable
as $$
  select r is null or (
    jsonb_typeof(r) = 'object'
    and (r ->> 'type') in ('gregorian_yearly', 'hijri_yearly')
    and coalesce((r ->> 'month')::int, 0) between 1 and 12
    and coalesce((r ->> 'day')::int, 0) between 1 and (case when r ->> 'type' = 'hijri_yearly' then 30 else 31 end)
    and coalesce((r ->> 'spanDays')::int, 1) between 1 and 60
  );
$$;

-- ---------------------------------------------------------------- themes
create table if not exists public.themes (
  id                  uuid primary key default gen_random_uuid(),
  slug                text not null,
  kind                public.theme_kind not null,
  name                jsonb not null,                                    -- {"en": "...", "ar": "..."}
  status              public.theme_status not null default 'draft',
  priority            integer not null default 0,                         -- higher wins inside a layer
  scope_countries     text[] not null default array['*'],                 -- ISO 3166-1 alpha-2 or '*'
  scope_plans         text[] not null default array['*'],                 -- plan slugs or '*'
  scope_tenants       text[] not null default array['*'],                 -- tenant ids or '*'
  starts_at           timestamptz,
  ends_at             timestamptz,
  recurrence          jsonb,                                              -- {"type":"hijri_yearly","month":9,"day":1,"spanDays":30}
  allow_tenant_accent boolean not null default true,                      -- false = locked campaign
  tokens              jsonb not null default '{"light":{},"dark":{}}'::jsonb,   -- only the tokens this theme CHANGES
  copy                jsonb not null default '{}'::jsonb,                 -- {"greeting": {"en": "...", "ar": "..."}}
  motifs              text[] not null default '{}',                       -- crescent | sparkle | bars
  version             integer not null default 1,
  published_at        timestamptz,
  created_by          uuid,
  updated_by          uuid,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),
  deleted_at          timestamptz,
  constraint themes_slug_key            unique (slug),
  constraint themes_slug_format         check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  constraint themes_name_has_en         check (jsonb_typeof(name) = 'object' and name ? 'en'),
  constraint themes_priority_range      check (priority between 0 and 1000),
  constraint themes_scope_countries_ok  check (public.q_valid_countries(scope_countries)),
  constraint themes_scope_plans_ok      check (public.q_valid_wildcard_list(scope_plans)),
  constraint themes_scope_tenants_ok    check (public.q_valid_wildcard_list(scope_tenants)),
  constraint themes_window_order        check (ends_at is null or starts_at is null or ends_at > starts_at),
  constraint themes_recurrence_ok       check (public.q_valid_recurrence(recurrence)),
  constraint themes_tokens_ok           check (public.q_valid_tokens(tokens)),
  constraint themes_copy_is_object      check (jsonb_typeof(copy) = 'object'),
  constraint themes_base_is_global      check (kind <> 'base' or (scope_countries = array['*'] and recurrence is null and starts_at is null and ends_at is null))
);
comment on table public.themes is 'Admin-managed colour themes. A theme stores only the tokens it changes; the resolver layers base < country < event < tenant accent.';

-- exactly one live base theme
create unique index if not exists themes_one_published_base
  on public.themes (kind) where kind = 'base' and status = 'published' and deleted_at is null;
create index if not exists themes_live_idx            on public.themes (status, kind) where deleted_at is null;
create index if not exists themes_scope_countries_gin on public.themes using gin (scope_countries);

-- ---------------------------------------------------------------- version history (rollback)
create table if not exists public.theme_versions (
  id         uuid primary key default gen_random_uuid(),
  theme_id   uuid not null references public.themes (id) on delete cascade,
  version    integer not null,
  note       text,
  snapshot   jsonb not null,
  created_by uuid,
  created_at timestamptz not null default now(),
  unique (theme_id, version)
);

create or replace function public.themes_before_write() returns trigger
language plpgsql
as $$
begin
  if tg_op = 'UPDATE' then
    if (to_jsonb(new) - array['updated_at', 'version', 'updated_by', 'published_at'])
       is distinct from (to_jsonb(old) - array['updated_at', 'version', 'updated_by', 'published_at']) then
      new.version := old.version + 1;
    end if;
    if new.status = 'published' and old.status is distinct from 'published' then
      new.published_at := now();
    end if;
  elsif tg_op = 'INSERT' then
    if new.status = 'published' and new.published_at is null then
      new.published_at := now();
    end if;
  end if;
  new.updated_at := now();
  return new;
end;
$$;

create or replace function public.themes_after_write() returns trigger
language plpgsql security definer set search_path = public
as $$
begin
  insert into public.theme_versions (theme_id, version, snapshot, created_by)
  values (new.id, new.version, to_jsonb(new), new.updated_by)
  on conflict (theme_id, version) do update set snapshot = excluded.snapshot;
  -- wake the Laravel listener (and any LISTEN client) so clients refresh within a second
  perform pg_notify('theme_changed', json_build_object('id', new.id, 'slug', new.slug, 'version', new.version, 'status', new.status)::text);
  return null;
end;
$$;

drop trigger if exists trg_themes_before on public.themes;
create trigger trg_themes_before before insert or update on public.themes
  for each row execute function public.themes_before_write();
drop trigger if exists trg_themes_after on public.themes;
create trigger trg_themes_after after insert or update on public.themes
  for each row execute function public.themes_after_write();

-- Restore the content of an earlier version into the live row (admin only). Status is left unchanged.
create or replace function public.rollback_theme(p_theme uuid, p_version integer) returns public.themes
language plpgsql security definer set search_path = public
as $$
declare
  snap jsonb;
  result public.themes;
begin
  if not public.is_platform_admin() then
    raise exception 'forbidden' using errcode = '42501';
  end if;
  select snapshot into snap from public.theme_versions where theme_id = p_theme and version = p_version;
  if snap is null then
    raise exception 'theme % has no version %', p_theme, p_version using errcode = 'P0002';
  end if;
  update public.themes set
    name                = snap -> 'name',
    priority            = (snap ->> 'priority')::int,
    scope_countries     = array(select jsonb_array_elements_text(snap -> 'scope_countries')),
    scope_plans         = array(select jsonb_array_elements_text(snap -> 'scope_plans')),
    scope_tenants       = array(select jsonb_array_elements_text(snap -> 'scope_tenants')),
    starts_at           = nullif(snap ->> 'starts_at', '')::timestamptz,
    ends_at             = nullif(snap ->> 'ends_at', '')::timestamptz,
    recurrence          = case when snap -> 'recurrence' = 'null'::jsonb then null else snap -> 'recurrence' end,
    allow_tenant_accent = (snap ->> 'allow_tenant_accent')::boolean,
    tokens              = snap -> 'tokens',
    copy                = snap -> 'copy',
    motifs              = array(select jsonb_array_elements_text(snap -> 'motifs'))
  where id = p_theme
  returning * into result;
  return result;
end;
$$;

-- ---------------------------------------------------------------- per-country settings (time zone + moon-sighting offset)
create table if not exists public.theme_country_settings (
  country_code      text primary key check (country_code ~ '^[A-Z]{2}$'),
  timezone          text not null,
  hijri_offset_days smallint not null default 0 check (hijri_offset_days between -2 and 2),  -- +1 = local sighting starts one day later
  updated_at        timestamptz not null default now()
);
insert into public.theme_country_settings (country_code, timezone) values
  ('SA', 'Asia/Riyadh'), ('AE', 'Asia/Dubai'), ('EG', 'Africa/Cairo'), ('MA', 'Africa/Casablanca'),
  ('PK', 'Asia/Karachi'), ('FR', 'Europe/Paris'), ('ES', 'Europe/Madrid'), ('US', 'America/New_York')
on conflict (country_code) do nothing;

-- ---------------------------------------------------------------- merchant branding (the tenant accent layer)
create table if not exists public.tenant_branding (
  tenant_id      uuid primary key,
  accent         text check (accent is null or accent ~ '^#[0-9A-Fa-f]{6}$'),
  logo_path      text,        -- Supabase Storage (private bucket)
  signature_path text,
  updated_at     timestamptz not null default now()
);
do $$ begin
  if to_regclass('public.tenants') is not null
     and not exists (select 1 from pg_constraint where conname = 'tenant_branding_tenant_fk') then
    alter table public.tenant_branding
      add constraint tenant_branding_tenant_fk foreign key (tenant_id) references public.tenants (id) on delete cascade;
  end if;
end $$;

-- ---------------------------------------------------------------- row level security (second line of defence behind Laravel)
alter table public.themes                 enable row level security;
alter table public.theme_versions         enable row level security;
alter table public.theme_country_settings enable row level security;
alter table public.tenant_branding        enable row level security;

drop policy if exists themes_read_published on public.themes;
create policy themes_read_published on public.themes for select to anon, authenticated
  using (status = 'published' and deleted_at is null);
drop policy if exists themes_admin_all on public.themes;
create policy themes_admin_all on public.themes for all to authenticated
  using (public.is_platform_admin()) with check (public.is_platform_admin());

drop policy if exists theme_versions_admin on public.theme_versions;
create policy theme_versions_admin on public.theme_versions for all to authenticated
  using (public.is_platform_admin()) with check (public.is_platform_admin());

drop policy if exists country_settings_read on public.theme_country_settings;
create policy country_settings_read on public.theme_country_settings for select to anon, authenticated using (true);
drop policy if exists country_settings_admin on public.theme_country_settings;
create policy country_settings_admin on public.theme_country_settings for all to authenticated
  using (public.is_platform_admin()) with check (public.is_platform_admin());

drop policy if exists tenant_branding_own on public.tenant_branding;
create policy tenant_branding_own on public.tenant_branding for all to authenticated
  using (tenant_id = public.current_tenant_id()) with check (tenant_id = public.current_tenant_id());
drop policy if exists tenant_branding_admin_read on public.tenant_branding;
create policy tenant_branding_admin_read on public.tenant_branding for select to authenticated
  using (public.is_platform_admin());

-- ---------------------------------------------------------------- realtime (instant refresh when an admin publishes)
do $$ begin
  if exists (select 1 from pg_publication where pubname = 'supabase_realtime') then
    begin
      alter publication supabase_realtime add table public.themes;
    exception when duplicate_object then null;
    end;
  end if;
end $$;

commit;
