CREATE DATABASE synthetic_interests;
USE synthetic_interests;
CREATE TABLE digits (n INT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);
CREATE TABLE interests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 generation_id BIGINT UNSIGNED NOT NULL,
 salesforce_id VARCHAR(18) NOT NULL,
 migration_origin_lead_id VARCHAR(18) NULL,
 functional_created_at DATETIME NOT NULL,
 is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
 raw_payload JSON NULL,
 UNIQUE KEY gen_sf (generation_id, salesforce_id),
 UNIQUE KEY gen_origin (generation_id, migration_origin_lead_id),
 KEY gen_date (generation_id, functional_created_at, id)
) ENGINE=InnoDB;
SET @start = NOW(6);
INSERT INTO interests (generation_id,salesforce_id,migration_origin_lead_id,functional_created_at,raw_payload)
 SELECT 1, CONCAT('a',LPAD(number,17,'0')), CONCAT('b',LPAD(number,17,'0')), '2026-01-01 00:00:00', JSON_OBJECT('synthetic',REPEAT('x',512))
 FROM (SELECT a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 + f.n*100000 AS number
 FROM digits a CROSS JOIN digits b CROSS JOIN digits c CROSS JOIN digits d CROSS JOIN digits e CROSS JOIN digits f) x
 WHERE number < 270000;
SELECT 'seed', COUNT(*), TIMESTAMPDIFF(MICROSECOND,@start,NOW(6))/1000000 FROM interests WHERE generation_id=1;
SET @start = NOW(6);
INSERT INTO interests (generation_id,salesforce_id,migration_origin_lead_id,functional_created_at,is_deleted,raw_payload)
 SELECT 2,salesforce_id,migration_origin_lead_id,functional_created_at,is_deleted,raw_payload FROM interests WHERE generation_id=1;
SELECT 'copy', COUNT(*), TIMESTAMPDIFF(MICROSECOND,@start,NOW(6))/1000000 FROM interests WHERE generation_id=2;
ANALYZE TABLE interests;
SELECT table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema='synthetic_interests' AND table_name='interests';
