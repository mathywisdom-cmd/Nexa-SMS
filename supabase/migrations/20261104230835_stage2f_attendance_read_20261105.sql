-- NEXA SMS — STAGE 2F
-- Read-only, permission-controlled attendance access.
-- School-scoped SELECT only; attendance recording and editing remain closed.

begin;

grant select on table
  public.attendance_sessions,
  public.attendance_records
to authenticated;

create policy "stage2f_read_attendance_sessions"
on public.attendance_sessions
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('attendance.view'))
);

create policy "stage2f_read_attendance_records"
on public.attendance_records
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('attendance.view'))
);

commit;