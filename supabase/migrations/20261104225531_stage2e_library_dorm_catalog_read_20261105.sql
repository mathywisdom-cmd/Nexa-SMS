-- NEXA SMS — STAGE 2E
-- Read-only, school-scoped access to library catalogue and dormitory catalogue.
-- Book circulation requests remain protected because they contain user-linked activity.

begin;

grant select on table
  public.books,
  public.dorms
to authenticated;

create policy "stage2e_read_books"
on public.books
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('library.manage'))
);

create policy "stage2e_read_dorms"
on public.dorms
for select
to authenticated
using (
  school_id = (select private.current_school_id())
  and (select private.has_permission('school.manage'))
);

commit;