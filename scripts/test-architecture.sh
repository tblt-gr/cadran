#!/bin/sh
set -eu

php scripts/check-architecture.php apps/api/src
php scripts/check-workspace-scope.php apps/api/src apps/api/migrations

result_file=$(mktemp)
trap 'rm -f "$result_file"' EXIT

if php scripts/check-architecture.php tests/architecture/fixtures/invalid >"$result_file" 2>&1; then
  printf '%s\n' 'The representative forbidden dependency was not rejected.' >&2
  exit 1
fi

if ! grep -q 'Application must not depend on Infrastructure' "$result_file"; then
  printf '%s\n' 'The boundary checker failed without the expected diagnostic.' >&2
  exit 1
fi

printf '%s\n' 'The representative forbidden dependency was rejected.'

if php scripts/check-workspace-scope.php tests/architecture/fixtures/unscoped apps/api/migrations >"$result_file" 2>&1; then
  printf '%s\n' 'The representative unscoped query was not rejected.' >&2
  exit 1
fi

if ! grep -q 'without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'The workspace-scope guard failed without the expected diagnostic.' >&2
  exit 1
fi

if ! grep -q 'findEventWhileProjectingItsWorkspace() touches audit_events without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'Selecting workspace_id without filtering on it was not rejected.' >&2
  exit 1
fi

if ! grep -q 'findEventThroughTableConstant() touches audit_events without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'A scoped table referenced through a class constant was not rejected.' >&2
  exit 1
fi

if ! grep -q 'moveEventToAnotherWorkspace() updates audit_events without a workspace_id in its criteria' "$result_file"; then
  printf '%s\n' 'A cross-workspace write through the DBAL criteria API was not rejected.' >&2
  exit 1
fi

if ! grep -q 'findEventBesideAScopedQuery() touches audit_events without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'A scoped query was allowed to vouch for an unscoped one in the same method.' >&2
  exit 1
fi

if ! grep -q 'findEventThroughPartiallyScopedJoin() touches audit_events a without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'One scoped join participant was allowed to vouch for another.' >&2
  exit 1
fi

if ! grep -q 'findEventsThroughPartiallyScopedUnion() touches audit_events a without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'One scoped UNION branch was allowed to vouch for another occurrence.' >&2
  exit 1
fi

if ! grep -q 'findEventsThroughPartiallyScopedCommaJoin() touches identity_workspace_memberships m without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'A scoped table in a comma join was not checked.' >&2
  exit 1
fi

if ! grep -q 'findEventsThroughSuffixAlias() touches audit_events a without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'A longer alias suffix was allowed to constrain a shorter alias.' >&2
  exit 1
fi

if ! grep -q 'findEventThroughUnrecognisedGateway() reaches audit_events through an unrecognised query path' "$result_file"; then
  printf '%s\n' 'A recognised query occurrence masked an unrecognised one for the same table.' >&2
  exit 1
fi

if ! grep -q 'findEventThroughUnrecognisedGatewayArgument() reaches audit_events through an unrecognised query path' "$result_file"; then
  printf '%s\n' 'SQL outside argument zero escaped unrecognised-query attribution.' >&2
  exit 1
fi

if ! grep -q 'outside an Infrastructure layer' "$result_file"; then
  printf '%s\n' 'The workspace-scope guard did not report the misplaced query.' >&2
  exit 1
fi

printf '%s\n' 'The representative unscoped query was rejected.'

if ! php scripts/check-workspace-scope.php tests/architecture/fixtures/scoped apps/api/migrations >"$result_file" 2>&1; then
  cat "$result_file" >&2
  printf '%s\n' 'A fully scoped joined query was rejected.' >&2
  exit 1
fi

printf '%s\n' 'The representative fully scoped joined query was accepted.'

if php scripts/check-workspace-scope.php tests/architecture/fixtures/altered-scope tests/architecture/fixtures/altered-migrations >"$result_file" 2>&1; then
  printf '%s\n' 'A table scoped by a later migration was not detected.' >&2
  exit 1
fi

if ! grep -q 'findRecord() touches later_scoped_records without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'The later workspace_id migration failed without the expected diagnostic.' >&2
  exit 1
fi

if ! grep -q 'findRecordAfterColumnRename() touches renamed_column_records without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'A column renamed to workspace_id was not detected.' >&2
  exit 1
fi

if ! grep -q 'findRecordAfterTableRename() touches renamed_table_records without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'A workspace-scoped table rename was not followed.' >&2
  exit 1
fi

if ! grep -q 'findMultilineRecord() touches multiline_records without a workspace_id predicate' "$result_file"; then
  printf '%s\n' 'A table declared over several DDL lines was not detected as workspace-scoped.' >&2
  exit 1
fi

printf '%s\n' 'The table scoped by a later migration was detected.'

if php scripts/check-workspace-scope.php tests/architecture/fixtures/untrusted-scope apps/api/migrations >"$result_file" 2>&1; then
  printf '%s\n' 'A client-selected workspace scope was not rejected.' >&2
  exit 1
fi

if ! grep -q 'creates a WorkspaceScope outside a trusted resolution path' "$result_file"; then
  printf '%s\n' 'The workspace-scope provenance guard failed without the expected diagnostic.' >&2
  exit 1
fi

printf '%s\n' 'The representative client-selected workspace scope was rejected.'
