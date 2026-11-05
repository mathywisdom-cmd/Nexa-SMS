begin;

grant select on table public.student_records, public.staff_records to authenticated;

create policy "stage2g_read_student_records"
on public.student_records
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('students.view'))
);

create policy "stage2g_read_staff_records"
on public.staff_records
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('teachers.view'))
);

commit;