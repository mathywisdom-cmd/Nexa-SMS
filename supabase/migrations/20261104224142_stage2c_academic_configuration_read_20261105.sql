-- NEXA SMS — STAGE 2C
-- Read-only, permission-controlled academic configuration.
-- PROPOSED ONLY. Do not apply until approved.

begin;

grant select on table
  public.class_types,
  public.subjects,
  public.grades
to authenticated;

create policy "stage2c_read_class_types"
on public.class_types
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('classes.view'))
);

create policy "stage2c_read_subjects"
on public.subjects
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('subjects.view'))
);

create policy "stage2c_read_grades"
on public.grades
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('marks.view'))
);

commit;