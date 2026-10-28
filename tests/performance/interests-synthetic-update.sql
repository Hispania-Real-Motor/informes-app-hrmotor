USE synthetic_interests;
DELIMITER //
CREATE PROCEDURE apply_delta()
BEGIN
 DECLARE offset_value INT DEFAULT 0;
 SET @started = NOW(6);
 WHILE offset_value < 270000 DO
  UPDATE interests SET raw_payload = JSON_SET(raw_payload, '$.updated', TRUE)
  WHERE generation_id=2
    AND salesforce_id >= CONCAT('a',LPAD(offset_value,17,'0'))
    AND salesforce_id < CONCAT('a',LPAD(offset_value+1000,17,'0'));
  COMMIT;
  SET offset_value = offset_value + 1000;
 END WHILE;
 SELECT 'delta', TIMESTAMPDIFF(MICROSECOND,@started,NOW(6))/1000000 AS seconds;
END//
DELIMITER ;
CALL apply_delta();
