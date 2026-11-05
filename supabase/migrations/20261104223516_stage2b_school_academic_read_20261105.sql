-- NEXA SMS — STAGE 2B
-- Read-only, permission-controlled access to school academic structure.
-- PROPOSED ONLY. Do not apply until approved.

begin;

grant select on table
  public.academic_years,
  public.terms,
  public.my_classes,
  public.sections,
  public.exams
to authenticated;

create policy "stage2b_read_academic_years"
on public.academic_years
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('academic_years.manage'))
);

create policy "stage2b_read_terms"
on public.terms
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('terms.manage'))
);

create policy "stage2b_read_my_classes"
on public.my_classes
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('classes.view'))
);

create policy "stage2b_read_sections"
on public.sections
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('classes.view'))
);

create policy "stage2b_read_exams"
on public.exams
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('exams.view'))
);

commit;