-- NEXA SMS — STAGE 2A
-- Authenticated read-only reference data.
-- PROPOSED ONLY: do not apply until approved.

begin;

grant select on table
  public.blood_groups,
  public.states,
  public.lgas,
  public.nationalities,
  public.user_types,
  public.roles,
  public.permissions,
  public.role_permissions
to authenticated;

create policy "stage2a_authenticated_read_blood_groups"
on public.blood_groups
for select
to authenticated
using ((select auth.uid()) is not null);

create policy "stage2a_authenticated_read_states"
on public.states
for select
to authenticated
using ((select auth.uid()) is not null);

create policy "stage2a_authenticated_read_lgas"
on public.lgas
for select
to authenticated
using ((select auth.uid()) is not null);

create policy "stage2a_authenticated_read_nationalities"
on public.nationalities
for select
to authenticated
using ((select auth.uid()) is not null);

create policy "stage2a_authenticated_read_user_types"
on public.user_types
for select
to authenticated
using ((select auth.uid()) is not null);

create policy "stage2a_authenticated_read_roles"
on public.roles
for select
to authenticated
using ((select auth.uid()) is not null);

create policy "stage2a_authenticated_read_permissions"
on public.permissions
for select
to authenticated
using ((select auth.uid()) is not null);

create policy "stage2a_authenticated_read_role_permissions"
on public.role_permissions
for select
to authenticated
using ((select auth.uid()) is not null);

commit;