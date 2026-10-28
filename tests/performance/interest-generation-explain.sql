-- Run only against the disposable MariaDB benchmark database after migrations.
-- The generation predicate must remain the leading access path for readers.
EXPLAIN FORMAT=JSON
SELECT version.*
FROM salesforce_interest_versions AS version
INNER JOIN salesforce_interest_members AS member
  ON member.version_id = version.id
WHERE member.generation_id = 1
ORDER BY version.id
LIMIT 1000;

EXPLAIN FORMAT=JSON
SELECT member.salesforce_id, member.version_id
FROM salesforce_interest_members AS member
WHERE member.generation_id = 1
  AND member.salesforce_id IN ('synthetic-000000000000001');

EXPLAIN FORMAT=JSON
SELECT member.migration_origin_lead_id, member.salesforce_id
FROM salesforce_interest_members AS member
WHERE member.generation_id = 1
  AND member.migration_origin_lead_id IN ('synthetic-origin-000000000000001');
