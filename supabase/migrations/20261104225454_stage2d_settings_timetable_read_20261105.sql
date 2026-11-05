-- NEXA SMS — STAGE 2D
-- Read-only, school-scoped access to school settings and timetable data.
-- Applies under the user's standing approval for routine, non-destructive stages.

begin;

grant select on table
  public.settings,
  public.time_table_records,
  public.time_tables,
  public.time_slots
to authenticated;

create policy "stage2d_read_settings"
on public.settings
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('settings.manage'))
);

create policy "stage2d_read_time_table_records"
on public.time_table_records
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (
    (select private.has_permission('classes.view'))
    or (select private.has_permission('exams.view'))
  )
);

create policy "stage2d_read_time_tables"
on public.time_tables
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (
    (select private.has_permission('classes.view'))
    or (select private.has_permission('exams.view'))
  )
);

create policy "stage2d_read_time_slots"
on public.time_slots
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (
    (select private.has_permission('classes.view'))
    or (select private.has_permission('exams.view'))
  )
);

commit;