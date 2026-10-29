-- Run only against the disposable MariaDB benchmark database after migrations.
-- The generation predicate must remain the leading access path for readers.
EXPLAIN ANALYZE
SELECT member.version_id AS id, version.salesforce_id, version.content_hash, version.is_deleted
FROM salesforce_interest_members AS member
STRAIGHT_JOIN salesforce_interest_versions AS version
  ON version.id = member.version_id
WHERE member.generation_id = 1
ORDER BY member.version_id
LIMIT 1000;

-- Subsequent manifest page; replace 1000 with the prior member.version_id.
EXPLAIN ANALYZE
SELECT member.version_id AS id, version.salesforce_id, version.content_hash, version.is_deleted
FROM salesforce_interest_members AS member
STRAIGHT_JOIN salesforce_interest_versions AS version
  ON version.id = member.version_id
WHERE member.generation_id = 1
  AND member.version_id > 1000
ORDER BY member.version_id
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
